<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Broadcasting\SseBroadcasterInterface;
use Core\Container;
use Core\Database\Connection;
use Core\Events\Event;
use Core\Outbox\TransactionalOutboxManager;
use Core\Outbox\TransactionalOutboxManagerInterface;
use Core\Webhook\WebhookDispatcher;
use Exception;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TransactionalOutboxTest extends TestCase
{
    private string $tempDir;
    private string $tempDbPath;
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aio_outbox_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->tempDbPath = $this->tempDir . DIRECTORY_SEPARATOR . 'test_outbox.sqlite';
        $this->db = new Connection("sqlite:{$this->tempDbPath}");

        $this->db->statement("
            CREATE TABLE outbox_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NULL,
                event_id TEXT NOT NULL UNIQUE,
                event_name TEXT NOT NULL,
                payload TEXT NOT NULL,
                target_channel TEXT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                attempts INTEGER NOT NULL DEFAULT 0,
                max_attempts INTEGER NOT NULL DEFAULT 5,
                last_error TEXT NULL,
                published_at TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );
        ");

        $this->db->statement("
            CREATE TABLE invoices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                invoice_number TEXT NOT NULL,
                amount REAL NOT NULL
            );
        ");
    }

    protected function tearDown(): void
    {
        $this->db->close();
        $this->deleteRecursive($this->tempDir);
        parent::tearDown();
    }

    private function deleteRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->deleteRecursive($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    public function testRecordEventPersistsInDatabase(): void
    {
        $outbox = new TransactionalOutboxManager($this->db);

        $msg = $outbox->record(
            eventName: 'invoice.paid',
            payload: ['invoice_id' => 101, 'amount' => 750000],
            tenantId: 1,
            targetChannel: 'cashier.desk1'
        );

        $this->assertGreaterThan(0, $msg->id);
        $this->assertEquals('invoice.paid', $msg->eventName);
        $this->assertEquals('pending', $msg->status);
        $this->assertEquals(0, $msg->attempts);

        $row = $this->db->selectOne("SELECT * FROM outbox_events WHERE id = ?", [$msg->id]);
        $this->assertNotNull($row);
        $this->assertEquals('invoice.paid', $row['event_name']);
        $this->assertEquals('cashier.desk1', $row['target_channel']);
    }

    public function testAtomicRollbackPreventsGhostActions(): void
    {
        $outbox = new TransactionalOutboxManager($this->db);

        try {
            $this->db->transaction(function () use ($outbox) {
                // 1. Business mutation
                $this->db->statement("INSERT INTO invoices (invoice_number, amount) VALUES ('INV-FAIL-01', 500000)");

                // 2. Outbox event recording
                $outbox->record('invoice.paid', ['invoice_number' => 'INV-FAIL-01', 'amount' => 500000], tenantId: 1);

                // 3. Unexpected failure occurs before commit
                throw new RuntimeException("Simulated error during business transaction!");
            });
        } catch (Exception) {
            // Expected rollback
        }

        // Verify neither the invoice nor the outbox event exist! Zero Ghost Actions!
        $invoiceCount = (int) $this->db->selectOne("SELECT COUNT(*) as c FROM invoices")['c'];
        $outboxCount = (int) $this->db->selectOne("SELECT COUNT(*) as c FROM outbox_events")['c'];

        $this->assertEquals(0, $invoiceCount, "Invoices table must be empty due to rollback.");
        $this->assertEquals(0, $outboxCount, "Outbox event must NOT be saved if transaction rolls back!");
    }

    public function testSuccessfulTransactionCommitsOutboxEvent(): void
    {
        $outbox = new TransactionalOutboxManager($this->db);

        $this->db->transaction(function () use ($outbox) {
            $this->db->statement("INSERT INTO invoices (invoice_number, amount) VALUES ('INV-SUCCESS-01', 250000)");
            $outbox->record('invoice.created', ['invoice_number' => 'INV-SUCCESS-01'], tenantId: 1);
        });

        $invoiceCount = (int) $this->db->selectOne("SELECT COUNT(*) as c FROM invoices")['c'];
        $outboxCount = (int) $this->db->selectOne("SELECT COUNT(*) as c FROM outbox_events")['c'];

        $this->assertEquals(1, $invoiceCount);
        $this->assertEquals(1, $outboxCount);
    }

    public function testRelayPublishesEventsAndUpdatesStatus(): void
    {
        $sseMock = $this->createMock(SseBroadcasterInterface::class);
        $sseMock->expects($this->once())
            ->method('publish')
            ->with('billing.channel', 'invoice.paid', ['invoice_id' => 99], '1');

        $outbox = new TransactionalOutboxManager($this->db, sseBroadcaster: $sseMock);

        $outbox->record(
            eventName: 'invoice.paid',
            payload: ['invoice_id' => 99],
            tenantId: 1,
            targetChannel: 'billing.channel'
        );

        $this->assertEquals(1, $outbox->getPendingCount());

        $result = $outbox->relay(10);

        $this->assertEquals(1, $result['published']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals(0, $outbox->getPendingCount());

        $row = $this->db->selectOne("SELECT status, published_at, attempts FROM outbox_events LIMIT 1");
        $this->assertEquals('published', $row['status']);
        $this->assertEquals(1, (int) $row['attempts']);
        $this->assertNotNull($row['published_at']);
    }

    public function testRelayRetriesOnFailureAndTransitionsToFailedOnMaxAttempts(): void
    {
        $failingSse = $this->createMock(SseBroadcasterInterface::class);
        $failingSse->method('publish')
            ->willThrowException(new RuntimeException("Push server unavailable"));

        $outbox = new TransactionalOutboxManager($this->db, sseBroadcaster: $failingSse);

        $outbox->record(
            eventName: 'order.placed',
            payload: ['order_id' => 5],
            tenantId: 1,
            targetChannel: 'orders'
        );

        // Attempt 1 to 4
        for ($i = 1; $i <= 4; $i++) {
            $res = $outbox->relay(10);
            $this->assertEquals(0, $res['published']);
            $this->assertEquals(1, $res['failed']);

            $row = $this->db->selectOne("SELECT status, attempts, last_error FROM outbox_events LIMIT 1");
            $this->assertEquals('pending', $row['status']);
            $this->assertEquals($i, (int) $row['attempts']);
            $this->assertStringContainsString('Push server unavailable', $row['last_error']);
        }

        // Attempt 5 (max_attempts = 5 reached)
        $res5 = $outbox->relay(10);
        $this->assertEquals(0, $res5['published']);
        $this->assertEquals(1, $res5['failed']);

        $rowFinal = $this->db->selectOne("SELECT status, attempts FROM outbox_events LIMIT 1");
        $this->assertEquals('failed', $rowFinal['status']);
        $this->assertEquals(5, (int) $rowFinal['attempts']);
    }

    public function testDrainAllAndPrune(): void
    {
        $outbox = new TransactionalOutboxManager($this->db);

        for ($i = 1; $i <= 10; $i++) {
            $outbox->record("event.{$i}", ['i' => $i], tenantId: 1);
        }

        $this->assertEquals(10, $outbox->getPendingCount());

        $drained = $outbox->drainAll(5);
        $this->assertEquals(10, $drained);
        $this->assertEquals(0, $outbox->getPendingCount());

        $stats = $outbox->getStats();
        $this->assertEquals(10, $stats['published']);
        $this->assertEquals(0, $stats['pending']);

        // Prune events older than 0 days
        $pruned = $outbox->prune(keepDays: 0);
        $this->assertEquals(10, $pruned);

        $statsAfter = $outbox->getStats();
        $this->assertEquals(0, $statsAfter['total']);
    }

    public function testGlobalOutboxHelper(): void
    {
        $container = Container::getInstance();
        $container->singleton(TransactionalOutboxManagerInterface::class, function () {
            return new TransactionalOutboxManager($this->db);
        });

        $this->assertTrue(function_exists('outbox'));
        $this->assertInstanceOf(TransactionalOutboxManagerInterface::class, outbox());
    }
}
