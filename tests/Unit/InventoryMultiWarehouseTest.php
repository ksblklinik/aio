<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Facility\Contracts\FacilityRegistryInterface;
use App\Modules\Facility\Services\FacilityService;
use App\Modules\Inventory\Contracts\InventoryRegistryInterface;
use App\Modules\Inventory\Services\InventoryService;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use Core\Sequence\SequenceManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class InventoryMultiWarehouseTest extends TestCase
{
    private Connection $db;
    private Migrator $migrator;
    private TenantContext $tenantContext;
    private InventoryService $inventory;
    private FacilityService $facility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new Connection('sqlite::memory:');
        $this->migrator = new Migrator($this->db);
        $this->migrator->runMigrationsFrom(__DIR__ . '/../../database/migrations');

        $this->tenantContext = TenantContext::getInstance();
        $this->tenantContext->clear();

        $container = \Core\Container::getInstance();
        $container->instance(Connection::class, $this->db);
        $container->instance(TenantContext::class, $this->tenantContext);
        $container->instance(SequenceManager::class, new SequenceManager($this->db));

        $this->db->statement("INSERT INTO tenants (id, name, slug) VALUES (1, 'Klinik Medika Pratama', 'klinik-medika')");
        $this->db->statement("INSERT INTO tenants (id, name, slug) VALUES (2, 'Posko Darurat Bencana', 'posko-darurat')");

        $this->facility = new FacilityService($this->db, $this->tenantContext);
        $container->instance(FacilityRegistryInterface::class, $this->facility);

        $this->inventory = new InventoryService($this->db, $this->tenantContext);
        $container->instance(InventoryRegistryInterface::class, $this->inventory);
    }

    protected function tearDown(): void
    {
        $this->tenantContext->clear();
        parent::tearDown();
    }

    public function test_can_create_batch_with_expiry_date(): void
    {
        $this->tenantContext->setTenantId('1');

        $itemId = $this->inventory->createItem([
            'name' => 'Paracetamol 500mg',
            'type' => 'goods',
            'cost_price' => 5000,
            'selling_price' => 7500,
        ]);

        $batchId = $this->inventory->createBatch(
            $itemId,
            'BATCH-PCT-2026',
            '2026-12-31',
            100.0,
            5000.0,
            'Batch pabrikan Kimia Farma'
        );

        $this->assertGreaterThan(0, $batchId);

        $batch = $this->inventory->getBatch($batchId);
        $this->assertNotNull($batch);
        $this->assertEquals('BATCH-PCT-2026', $batch['batch_number']);
        $this->assertEquals('2026-12-31', $batch['expiry_date']);
        $this->assertEquals('active', $batch['status']);
        $this->assertEquals(100.0, (float) $batch['initial_quantity']);
        $this->assertEquals(100.0, (float) $batch['current_quantity']);
    }

    public function test_record_stock_in_with_room_and_batch_updates_location_stock(): void
    {
        $this->tenantContext->setTenantId('1');

        // Setup Location and Storage Room via Facility
        $loc = $this->facility->createLocation(['name' => 'Gedung Utama']);
        $room = $this->facility->createRoom([
            'location_id' => $loc['id'],
            'code' => 'GDG-FARMASI',
            'name' => 'Gudang Farmasi Induk',
            'type' => 'storage',
        ]);

        $itemId = $this->inventory->createItem([
            'name' => 'Amoxicillin 500mg',
            'type' => 'goods',
            'cost_price' => 12000,
        ]);

        $batchId = $this->inventory->createBatch(
            $itemId,
            'AMX-2026-001',
            '2027-06-30',
            0.0,
            12000.0
        );

        // Record stock in to specific room & batch
        $movId = $this->inventory->recordStockMovement(
            $itemId,
            50.0,
            'in',
            'Penerimaan DO #1234',
            'purchase_order',
            'PO/2026/001',
            12000.0,
            null,
            $room['id'],
            $batchId
        );

        $this->assertGreaterThan(0, $movId);

        // Verify total stock on item
        $this->assertEquals(50.0, $this->inventory->getStock($itemId));

        // Verify room stock
        $this->assertEquals(50.0, $this->inventory->getLocationStock($itemId, $room['id']));

        // Verify batch stock
        $batch = $this->inventory->getBatch($batchId);
        $this->assertEquals(50.0, (float) $batch['current_quantity']);

        // Verify stock by location list
        $locStocks = $this->inventory->getStockByLocation($itemId);
        $this->assertCount(1, $locStocks);
        $this->assertEquals('Gudang Farmasi Induk', $locStocks[0]['room_name']);
        $this->assertEquals(50.0, (float) $locStocks[0]['quantity']);
        $this->assertEquals('AMX-2026-001', $locStocks[0]['batch_number']);
    }

    public function test_record_stock_out_validates_room_and_batch_stock(): void
    {
        $this->tenantContext->setTenantId('1');

        $loc = $this->facility->createLocation(['name' => 'Gedung Medika']);
        $room = $this->facility->createRoom([
            'location_id' => $loc['id'],
            'name' => 'Depo Obat UGD',
            'type' => 'storage',
        ]);

        $itemId = $this->inventory->createItem(['name' => 'Cefixime 100mg']);
        $batchId = $this->inventory->createBatch($itemId, 'CFX-01', '2027-01-01');

        // Stock in 20 units
        $this->inventory->recordStockMovement($itemId, 20.0, 'in', 'Inbound', null, null, null, null, $room['id'], $batchId);

        // Attempt stock out 25 units from room (should fail)
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stok di ruangan ini tidak mencukupi.');
        $this->inventory->recordStockMovement($itemId, 25.0, 'out', 'Outbound', null, null, null, null, $room['id'], $batchId);
    }

    public function test_fefo_deduct_allocates_earliest_expiring_batch_first(): void
    {
        $this->tenantContext->setTenantId('1');

        $loc = $this->facility->createLocation(['name' => 'Klinik Sehat']);
        $room = $this->facility->createRoom([
            'location_id' => $loc['id'],
            'name' => 'Apotek Farmasi',
            'type' => 'storage',
        ]);

        $itemId = $this->inventory->createItem(['name' => 'Insulin Glargine Pen']);

        // Batch 1: Expiring earlier (2026-11-30), stock: 10
        $batch1Id = $this->inventory->createBatch($itemId, 'BATCH-EARLY', '2026-11-30');
        $this->inventory->recordStockMovement($itemId, 10.0, 'in', 'Stok Batch Awal', null, null, null, null, $room['id'], $batch1Id);

        // Batch 2: Expiring later (2027-05-30), stock: 20
        $batch2Id = $this->inventory->createBatch($itemId, 'BATCH-LATER', '2027-05-30');
        $this->inventory->recordStockMovement($itemId, 20.0, 'in', 'Stok Batch Lanjut', null, null, null, null, $room['id'], $batch2Id);

        $this->assertEquals(30.0, $this->inventory->getStock($itemId));

        // Deduct 15 units via FEFO: should take 10 from BATCH-EARLY, and 5 from BATCH-LATER
        $allocations = $this->inventory->deductStockFefo($itemId, 15.0, $room['id'], 'Resep Dokter #99');

        $this->assertCount(2, $allocations);

        // Allocation 1: BATCH-EARLY full 10
        $this->assertEquals($batch1Id, $allocations[0]['batch_id']);
        $this->assertEquals('BATCH-EARLY', $allocations[0]['batch_number']);
        $this->assertEquals(10.0, (float) $allocations[0]['quantity']);

        // Allocation 2: BATCH-LATER remaining 5
        $this->assertEquals($batch2Id, $allocations[1]['batch_id']);
        $this->assertEquals('BATCH-LATER', $allocations[1]['batch_number']);
        $this->assertEquals(5.0, (float) $allocations[1]['quantity']);

        // Check balances
        $b1 = $this->inventory->getBatch($batch1Id);
        $b2 = $this->inventory->getBatch($batch2Id);
        $this->assertEquals(0.0, (float) $b1['current_quantity']);
        $this->assertEquals(15.0, (float) $b2['current_quantity']);

        // Check remaining stock
        $this->assertEquals(15.0, $this->inventory->getStock($itemId));
        $this->assertEquals(15.0, $this->inventory->getLocationStock($itemId, $room['id']));
    }

    public function test_inter_warehouse_stock_transfer(): void
    {
        $this->tenantContext->setTenantId('1');

        $loc = $this->facility->createLocation(['name' => 'Rumah Sakit Utama']);
        $sourceRoom = $this->facility->createRoom([
            'location_id' => $loc['id'],
            'name' => 'Gudang Sentral Farmasi',
            'type' => 'storage',
        ]);
        $destRoom = $this->facility->createRoom([
            'location_id' => $loc['id'],
            'name' => 'Apotek Rawat Jalan',
            'type' => 'storage',
        ]);

        $itemId = $this->inventory->createItem(['name' => 'Ketorolac Ampul']);
        $batchId = $this->inventory->createBatch($itemId, 'KTR-2026', '2027-12-31');

        // Inbound 100 units to source room
        $this->inventory->recordStockMovement($itemId, 100.0, 'in', 'Inbound DO', null, null, null, null, $sourceRoom['id'], $batchId);

        $this->assertEquals(100.0, $this->inventory->getLocationStock($itemId, $sourceRoom['id']));
        $this->assertEquals(0.0, $this->inventory->getLocationStock($itemId, $destRoom['id']));

        // Transfer 40 units from source to destination
        $transfer = $this->inventory->transferStock(
            $sourceRoom['id'],
            $destRoom['id'],
            [
                [
                    'item_id' => $itemId,
                    'quantity' => 40.0,
                    'batch_id' => $batchId,
                ],
            ],
            'Transfer ampul mingguan untuk poli'
        );

        $this->assertNotEmpty($transfer['id']);
        $this->assertStringStartsWith('TRF/', $transfer['transfer_number']);
        $this->assertEquals('completed', $transfer['status']);
        $this->assertCount(1, $transfer['items']);

        // Check balances after transfer
        $this->assertEquals(60.0, $this->inventory->getLocationStock($itemId, $sourceRoom['id']));
        $this->assertEquals(40.0, $this->inventory->getLocationStock($itemId, $destRoom['id']));
        $this->assertEquals(100.0, $this->inventory->getStock($itemId)); // Overall total unchanged!
    }

    public function test_cannot_transfer_stock_if_source_room_has_insufficient_stock(): void
    {
        $this->tenantContext->setTenantId('1');

        $loc = $this->facility->createLocation(['name' => 'Klinik']);
        $sourceRoom = $this->facility->createRoom(['location_id' => $loc['id'], 'name' => 'Gudang A', 'type' => 'storage']);
        $destRoom = $this->facility->createRoom(['location_id' => $loc['id'], 'name' => 'Gudang B', 'type' => 'storage']);

        $itemId = $this->inventory->createItem(['name' => 'Alkohol 70% 1L']);
        $this->inventory->recordStockMovement($itemId, 10.0, 'in', 'Inbound', null, null, null, null, $sourceRoom['id']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stok di gudang asal tidak mencukupi untuk item Alkohol 70% 1L');
        $this->inventory->transferStock(
            $sourceRoom['id'],
            $destRoom['id'],
            [['item_id' => $itemId, 'quantity' => 25.0]]
        );
    }

    public function test_near_expiry_alert_lists_items_expiring_within_threshold(): void
    {
        $this->tenantContext->setTenantId('1');

        $item1 = $this->inventory->createItem(['name' => 'Vaksin Influenza']);
        $item2 = $this->inventory->createItem(['name' => 'Vitamin C 500mg']);

        $today = date('Y-m-d');
        $expiringIn15Days = date('Y-m-d', strtotime('+15 days'));
        $expiringIn90Days = date('Y-m-d', strtotime('+90 days'));

        $b1 = $this->inventory->createBatch($item1, 'VAK-NEAR', $expiringIn15Days);
        $this->inventory->recordStockMovement($item1, 20.0, 'in', 'In', null, null, null, null, null, $b1);

        $b2 = $this->inventory->createBatch($item2, 'VIT-FAR', $expiringIn90Days);
        $this->inventory->recordStockMovement($item2, 100.0, 'in', 'In', null, null, null, null, null, $b2);

        // Threshold 30 days: should only include Vaksin Influenza
        $nearExpiry30 = $this->inventory->listNearExpiryItems(30);
        $this->assertCount(1, $nearExpiry30);
        $this->assertEquals('Vaksin Influenza', $nearExpiry30[0]['item_name']);
        $this->assertEquals('VAK-NEAR', $nearExpiry30[0]['batch_number']);
        $this->assertEquals(20.0, (float) $nearExpiry30[0]['current_quantity']);

        // Threshold 100 days: should include both
        $nearExpiry100 = $this->inventory->listNearExpiryItems(100);
        $this->assertCount(2, $nearExpiry100);
    }

    public function test_multi_tenant_isolation_in_batches_and_locations(): void
    {
        // Tenant 1
        $this->tenantContext->setTenantId('1');
        $locT1 = $this->facility->createLocation(['name' => 'Gedung T1']);
        $roomT1 = $this->facility->createRoom(['location_id' => $locT1['id'], 'name' => 'Gudang T1', 'type' => 'storage']);
        $itemT1 = $this->inventory->createItem(['name' => 'Obat T1']);
        $batchT1 = $this->inventory->createBatch($itemT1, 'BATCH-T1', '2027-01-01');
        $this->inventory->recordStockMovement($itemT1, 50.0, 'in', 'In', null, null, null, null, $roomT1['id'], $batchT1);

        // Tenant 2
        $this->tenantContext->setTenantId('2');
        $this->assertNull($this->inventory->getBatch($batchT1));
        $this->assertEquals(0.0, $this->inventory->getLocationStock($itemT1, $roomT1['id']));

        $t2Near = $this->inventory->listNearExpiryItems(365);
        $this->assertCount(0, $t2Near);
    }
}
