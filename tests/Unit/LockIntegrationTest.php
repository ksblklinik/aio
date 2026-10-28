<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Schedule\Services\ScheduleService;
use Core\Container;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use Core\Lock\Drivers\DatabaseLockDriver;
use Core\Lock\LockManager;
use Core\Lock\LockManagerInterface;
use Core\Lock\LockTimeoutException;
use Core\Sequence\SequenceManager;
use PHPUnit\Framework\TestCase;

class LockIntegrationTest extends TestCase
{
    private Connection $db;
    private LockManager $lockManager;
    private TenantContext $tenantContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new Connection('sqlite::memory:');
        $migrator = new Migrator($this->db);
        $migrator->runMigrationsFrom(__DIR__ . '/../../database/migrations');

        $this->tenantContext = TenantContext::getInstance();
        $this->tenantContext->clear();
        $this->tenantContext->setTenantId('1');

        $driver = new DatabaseLockDriver($this->db);
        $this->lockManager = new LockManager($driver);

        $container = Container::getInstance();
        $container->instance(Connection::class, $this->db);
        $container->instance(TenantContext::class, $this->tenantContext);
        $container->instance(LockManager::class, $this->lockManager);
        $container->instance(LockManagerInterface::class, $this->lockManager);
        $container->instance(SequenceManager::class, new SequenceManager($this->db, $this->tenantContext));

        // Seed tenant
        $this->db->statement("INSERT INTO tenants (id, name, slug) VALUES (1, 'Klinik Sehat Utama', 'klinik-sehat')");
        // Seed user
        $this->db->statement("INSERT INTO users (id, name, email, password_hash) VALUES (1, 'Admin', 'admin@example.com', 'secret')");
        // Seed facility room
        $this->db->statement("INSERT INTO facilities_locations (id, tenant_id, code, name, type, is_active) VALUES (1, 1, 'LOC-01', 'Gedung Utama', 'clinic', 1)");
        $this->db->statement("INSERT INTO facilities_rooms (id, tenant_id, location_id, code, name, floor, type, capacity, is_active) VALUES (101, 1, 1, 'POLI-01', 'Poli Umum', '1', 'service', 10, 1)");
    }

    protected function tearDown(): void
    {
        $this->tenantContext->clear();
        parent::tearDown();
    }

    public function testSequenceGenerationWithAtomicLock(): void
    {
        $seqManager = new SequenceManager($this->db, $this->tenantContext);

        $seqName = 'test_doc_' . bin2hex(random_bytes(4));

        $num1 = $seqManager->next($seqName, 'DOC', 4);
        $num2 = $seqManager->next($seqName, 'DOC', 4);
        $num3 = $seqManager->next($seqName, 'DOC', 4);

        $this->assertSame('DOC-0001', $num1);
        $this->assertSame('DOC-0002', $num2);
        $this->assertSame('DOC-0003', $num3);
    }

    public function testQueueTicketIssuanceAndCallingWithLock(): void
    {
        $scheduleService = new ScheduleService($this->db, $this->tenantContext);

        $prefix = 'Q' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 2));
        $today = date('Y-m-d');

        // Issue 3 tickets
        $ticket1 = $scheduleService->issueQueueTicket([
            'prefix' => $prefix,
            'ticket_date' => $today,
            'service_name' => 'Poli Test',
            'client_name' => 'Pasien A',
            'room_id' => 101,
        ]);
        $ticket2 = $scheduleService->issueQueueTicket([
            'prefix' => $prefix,
            'ticket_date' => $today,
            'service_name' => 'Poli Test',
            'client_name' => 'Pasien B',
            'room_id' => 101,
        ]);
        $ticket3 = $scheduleService->issueQueueTicket([
            'prefix' => $prefix,
            'ticket_date' => $today,
            'service_name' => 'Poli Test',
            'client_name' => 'Pasien C',
            'room_id' => 101,
        ]);

        $this->assertSame("{$prefix}-001", $ticket1['ticket_number']);
        $this->assertSame("{$prefix}-002", $ticket2['ticket_number']);
        $this->assertSame("{$prefix}-003", $ticket3['ticket_number']);

        // Call next ticket
        $called1 = $scheduleService->callNextTicket($prefix);
        $this->assertNotNull($called1);
        $this->assertSame("{$prefix}-001", $called1['ticket_number']);
        $this->assertSame('called', $called1['status']);

        $called2 = $scheduleService->callNextTicket($prefix);
        $this->assertNotNull($called2);
        $this->assertSame("{$prefix}-002", $called2['ticket_number']);
        $this->assertSame('called', $called2['status']);
    }

    public function testInventoryFefoDeductionWithAtomicLock(): void
    {
        $inventoryService = new InventoryService($this->db, $this->tenantContext);

        // Create an inventory item
        $sku = 'TEST-MED-' . bin2hex(random_bytes(3));
        $itemId = $inventoryService->createItem([
            'sku' => $sku,
            'name' => 'Obat Uji Kunci Konkurensi',
            'type' => 'goods',
            'unit' => 'tablet',
            'track_inventory' => 1,
            'minimum_stock' => 2,
        ]);

        // Add 10 units of stock
        $inventoryService->recordStockMovement(
            $itemId,
            10.0,
            'in',
            'Batch A Masuk',
            null,
            null,
            null,
            null,
            null,
            null
        );

        // Deduct 6 units
        $alloc1 = $inventoryService->deductStockFefo($itemId, 6.0, null, 'Resep #1');
        $this->assertNotEmpty($alloc1);

        $reloaded = $inventoryService->getItem($itemId);
        $this->assertEquals(4.0, (float) $reloaded['current_stock']);

        // Deduct 4 units
        $alloc2 = $inventoryService->deductStockFefo($itemId, 4.0, null, 'Resep #2');
        $this->assertNotEmpty($alloc2);

        $reloadedAfter = $inventoryService->getItem($itemId);
        $this->assertEquals(0.0, (float) $reloadedAfter['current_stock']);

        // Attempt to deduct 1 more unit -> Must fail, preventing negative stock
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Total stok tidak mencukupi');
        $inventoryService->deductStockFefo($itemId, 1.0, null, 'Resep #3');
    }

    public function testLockContentionTimeoutThrowsException(): void
    {
        $resourceKey = 'test:contention:resource_' . bin2hex(random_bytes(4));

        // Process 1 acquires lock
        $lock1 = $this->lockManager->acquire($resourceKey, ttlSeconds: 10, waitTimeoutMs: 0);
        $this->assertNotNull($lock1);
        $this->assertTrue($lock1->isAcquired());

        // Process 2 tries to acquire with 50ms waitTimeout -> should fail and throw LockTimeoutException
        $this->expectException(LockTimeoutException::class);

        try {
            $this->lockManager->run($resourceKey, function () {
                return 'never reached';
            }, ttlSeconds: 5, waitTimeoutMs: 50);
        } finally {
            $lock1->release();
        }
    }
}
