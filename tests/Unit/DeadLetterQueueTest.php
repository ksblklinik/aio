<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Container;
use Core\Database\Connection;
use Core\Queue\DeadLetterQueue;
use Core\Queue\DeadLetterQueueInterface;
use PHPUnit\Framework\TestCase;

class DeadLetterQueueTest extends TestCase
{
    private string $tempDir;
    private string $tempDbPath;
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aio_dlq_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->tempDbPath = $this->tempDir . DIRECTORY_SEPARATOR . 'test_dlq.sqlite';
        $this->db = new Connection("sqlite:{$this->tempDbPath}");

        $this->db->statement("
            CREATE TABLE jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER,
                queue TEXT NOT NULL,
                payload TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                reserved_at TEXT,
                available_at TEXT NOT NULL,
                created_at TEXT NOT NULL
            );
        ");

        $this->db->statement("
            CREATE TABLE failed_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER,
                queue TEXT NOT NULL,
                payload TEXT NOT NULL,
                exception TEXT NOT NULL,
                failed_at TEXT NOT NULL
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

    public function testListAndCountFailedJobs(): void
    {
        $dlq = new DeadLetterQueue($this->db);

        $this->assertEquals(0, $dlq->count());

        $now = date('Y-m-d H:i:s');
        $this->db->statement("
            INSERT INTO failed_jobs (tenant_id, queue, payload, exception, failed_at)
            VALUES (1, 'default', '{\"job\":\"App\\\\Jobs\\\\SendEmail\"}', 'RuntimeException: Mailer error', ?)
        ", [$now]);
        $this->db->statement("
            INSERT INTO failed_jobs (tenant_id, queue, payload, exception, failed_at)
            VALUES (1, 'notifications', '{\"job\":\"App\\\\Jobs\\\\SendSms\"}', 'TimeoutException: Gateway timeout', ?)
        ", [$now]);

        $this->assertEquals(2, $dlq->count());
        $this->assertEquals(1, $dlq->count('notifications'));
        $this->assertCount(2, $dlq->list());
        $this->assertCount(1, $dlq->list('notifications'));
    }

    public function testRetrySingleFailedJobMovesToJobsTable(): void
    {
        $dlq = new DeadLetterQueue($this->db);

        $now = date('Y-m-d H:i:s');
        $this->db->statement("
            INSERT INTO failed_jobs (id, tenant_id, queue, payload, exception, failed_at)
            VALUES (42, 1, 'webhooks', '{\"job\":\"SendWebhookJob\"}', 'HttpException: 504 Gateway', ?)
        ", [$now]);

        $this->assertEquals(1, $dlq->count());
        $this->assertEquals(0, (int) $this->db->selectOne("SELECT COUNT(*) as c FROM jobs")['c']);

        $retried = $dlq->retry(42);
        $this->assertTrue($retried);

        // Should be removed from failed_jobs
        $this->assertEquals(0, $dlq->count());

        // Should be placed in jobs
        $job = $this->db->selectOne("SELECT * FROM jobs WHERE queue = 'webhooks'");
        $this->assertNotNull($job);
        $this->assertEquals(0, (int) $job['attempts']);
        $this->assertNull($job['reserved_at']);
    }

    public function testRetryBatchAndRetryAll(): void
    {
        $dlq = new DeadLetterQueue($this->db);
        $now = date('Y-m-d H:i:s');

        for ($i = 1; $i <= 5; $i++) {
            $this->db->statement("
                INSERT INTO failed_jobs (id, tenant_id, queue, payload, exception, failed_at)
                VALUES (?, 1, 'batch_queue', '{\"job\":\"Job#{$i}\"}', 'TestException', ?)
            ", [$i, $now]);
        }

        $this->assertEquals(5, $dlq->count());

        // Batch retry 2 jobs
        $res = $dlq->retryBatch([1, 2]);
        $this->assertEquals(2, $res['retried']);
        $this->assertEquals(0, $res['failed']);
        $this->assertEquals(3, $dlq->count());

        // Retry all remaining
        $count = $dlq->retryAll('batch_queue');
        $this->assertEquals(3, $count);
        $this->assertEquals(0, $dlq->count());
        $this->assertEquals(5, (int) $this->db->selectOne("SELECT COUNT(*) as c FROM jobs")['c']);
    }

    public function testPurgeFailedJobs(): void
    {
        $dlq = new DeadLetterQueue($this->db);

        $oldDate = date('Y-m-d H:i:s', strtotime('-40 days'));
        $recentDate = date('Y-m-d H:i:s', strtotime('-5 days'));

        $this->db->statement("INSERT INTO failed_jobs (tenant_id, queue, payload, exception, failed_at) VALUES (1, 'q1', '{}', 'err', ?)", [$oldDate]);
        $this->db->statement("INSERT INTO failed_jobs (tenant_id, queue, payload, exception, failed_at) VALUES (1, 'q1', '{}', 'err', ?)", [$recentDate]);

        $this->assertEquals(2, $dlq->count());

        // Purge older than 30 days
        $purged = $dlq->purge(beforeDays: 30);
        $this->assertEquals(1, $purged);
        $this->assertEquals(1, $dlq->count());

        // Purge all remaining
        $purgedAll = $dlq->purge();
        $this->assertEquals(1, $purgedAll);
        $this->assertEquals(0, $dlq->count());
    }

    public function testDiagnosticsReturnsAccurateMetrics(): void
    {
        $dlq = new DeadLetterQueue($this->db);
        $now = date('Y-m-d H:i:s');

        $this->db->statement("
            INSERT INTO failed_jobs (tenant_id, queue, payload, exception, failed_at)
            VALUES (1, 'emails', '{}', 'InvalidArgumentException: Invalid address in file.php:12', ?)
        ", [$now]);
        $this->db->statement("
            INSERT INTO failed_jobs (tenant_id, queue, payload, exception, failed_at)
            VALUES (1, 'emails', '{}', 'InvalidArgumentException: Invalid address in file.php:12', ?)
        ", [$now]);
        $this->db->statement("
            INSERT INTO failed_jobs (tenant_id, queue, payload, exception, failed_at)
            VALUES (1, 'payments', '{}', 'RuntimeException: Connection dropped', ?)
        ", [$now]);

        $diag = $dlq->diagnostics();

        $this->assertEquals(3, $diag['total_failed']);
        $this->assertEquals(2, $diag['by_queue']['emails']);
        $this->assertEquals(1, $diag['by_queue']['payments']);
        $this->assertArrayHasKey('InvalidArgumentException', $diag['top_exceptions']);
    }

    public function testGlobalDlqHelper(): void
    {
        $container = Container::getInstance();
        $container->singleton(DeadLetterQueueInterface::class, function () {
            return new DeadLetterQueue($this->db);
        });

        $this->assertTrue(function_exists('dlq'));
        $this->assertInstanceOf(DeadLetterQueueInterface::class, dlq());
    }
}
