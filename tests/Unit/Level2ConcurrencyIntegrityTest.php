<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Billing\Services\BillingService;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Schedule\Services\ScheduleService;
use App\Modules\Tariff\Services\TariffService;
use Core\Database\Connection;
use Core\Database\TenantContext;
use Core\Lock\LockInterface;
use Core\Lock\LockManagerInterface;
use Core\Lock\LockTimeoutException;
use Core\Sequence\SequenceManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class Level2ConcurrencyIntegrityTest extends TestCase
{
    private string $tempDir;
    private string $tempDbPath;
    private Connection $db;
    private TenantContext $tenantContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aio_l2_concurrency_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->tempDbPath = $this->tempDir . DIRECTORY_SEPARATOR . 'l2_concurrency.sqlite';
        $this->db = new Connection("sqlite:{$this->tempDbPath}");

        $migrator = new \Core\Database\Migrator($this->db);
        $migrator->runMigrationsFrom(__DIR__ . '/../../database/migrations');

        $this->tenantContext = TenantContext::getInstance();
        $this->tenantContext->clear();
        $this->tenantContext->setTenantId('1');

        $container = \Core\Container::getInstance();
        $container->instance(Connection::class, $this->db);
        $container->instance(TenantContext::class, $this->tenantContext);
        $container->instance(SequenceManager::class, new SequenceManager($this->db));

        $this->seedBaseData();
    }

    protected function tearDown(): void
    {
        $this->tenantContext->clear();
        if (file_exists($this->tempDbPath)) {
            @unlink($this->tempDbPath);
        }
        if (is_dir($this->tempDir)) {
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    private function seedBaseData(): void
    {
        $this->db->statement("INSERT INTO tenants (id, name, slug) VALUES (1, 'Klinik Sehat', 'klinik-sehat');");
        $this->db->statement("INSERT INTO users (id, name, email, password_hash) VALUES (1, 'Admin', 'admin@example.com', 'secret');");
        $this->db->statement("INSERT INTO users (id, name, email, password_hash) VALUES (10, 'Dokter 1', 'doc1@example.com', 'secret');");
        $this->db->statement("INSERT INTO users (id, name, email, password_hash) VALUES (20, 'Dokter 2', 'doc2@example.com', 'secret');");

        $this->db->statement("INSERT INTO facilities_locations (id, tenant_id, code, name, type, is_active) VALUES (1, 1, 'LOC-01', 'Gedung Utama', 'clinic', 1);");
        $this->db->statement("INSERT INTO facilities_rooms (id, tenant_id, location_id, code, name, floor, type, capacity, is_active) VALUES (1, 1, 1, 'RM-01', 'Poli 1', '1', 'service', 10, 1);");
        $this->db->statement("INSERT INTO facilities_rooms (id, tenant_id, location_id, code, name, floor, type, capacity, is_active) VALUES (2, 1, 1, 'RM-02', 'Poli 2', '1', 'service', 10, 1);");
        $this->db->statement("INSERT INTO facilities_rooms (id, tenant_id, location_id, code, name, floor, type, capacity, is_active) VALUES (10, 1, 1, 'WH-01', 'Gudang Farmasi', '1', 'storage', 10, 1);");
        $this->db->statement("INSERT INTO facilities_spaces (id, tenant_id, room_id, code, name, type, status, is_active) VALUES (1, 1, 1, 'MEJA-01', 'Meja Dokter 1', 'desk', 'available', 1);");
    }

    public function testInventoryAtomicStockDecrementPreventsOversellingAndNegativeBalance(): void
    {
        $service = new InventoryService($this->db, $this->tenantContext);

        $itemId = $service->createItem([
            'name' => 'Amoxicillin 500mg',
            'type' => 'goods',
            'initial_stock' => 10.0,
            'cost_price' => 5000,
            'selling_price' => 8000,
        ]);

        $this->assertSame(10.0, $service->getStock($itemId));

        // Pengeluaran pertama 7 unit -> sisa 3 unit
        $service->recordStockMovement($itemId, 7.0, 'out', 'Penjualan Resep 1');
        $this->assertSame(3.0, $service->getStock($itemId));

        // Pengeluaran kedua 5 unit (melebihi sisa 3 unit) -> harus throw exception dan tidak boleh ada update
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Stok tidak mencukupi/i');
        $service->recordStockMovement($itemId, 5.0, 'out', 'Penjualan Resep 2');

        // Sisa stok di database harus tetap 3.0 dan tidak pernah negatif
        $this->assertSame(3.0, $service->getStock($itemId));
    }

    public function testFinanceAtomicOutflowPreventsSilentOverdraft(): void
    {
        $finance = new FinanceService($this->db, $this->tenantContext);

        $catId = $finance->createCategory(['code' => '5001', 'name' => 'Biaya Operasional', 'type' => 'expense']);
        $accId = $finance->createAccount([
            'code' => '1001',
            'name' => 'Kas Operasional',
            'initial_balance' => 500000.0,
        ]);

        // Outflow pertama Rp 300.000 -> sisa Rp 200.000
        $finance->recordOutflow($accId, 300000.0, $catId, null, 'Pembayaran Listrik');
        $account = $finance->getAccount($accId);
        $this->assertEquals(200000.0, (float) $account['current_balance']);

        // Outflow kedua Rp 250.000 (melebihi sisa Rp 200.000) -> harus gagal
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Saldo kas tidak mencukupi/i');
        $finance->recordOutflow($accId, 250000.0, $catId, null, 'Beli Perlengkapan Kantor');

        // Saldo tidak boleh berubah jadi negatif
        $accountFinal = $finance->getAccount($accId);
        $this->assertEquals(200000.0, (float) $accountFinal['current_balance']);
    }

    public function testBillingRecordPaymentAtomicDueValidationPreventsOverPayment(): void
    {
        $billing = new BillingService($this->db, $this->tenantContext);

        $invoice = $billing->createInvoice([
            'invoice_number' => 'INV/2026/100',
            'issue_date' => date('Y-m-d'),
            'items' => [
                ['item_name' => 'Konsultasi Dokter', 'item_type' => 'service', 'quantity' => 1, 'unit_price' => 150000.0],
            ],
        ]);

        $this->assertEquals(150000.0, (float) $invoice['amount_due']);

        // Pembayaran sebagian Rp 100.000 -> sisa Rp 50.000
        $res1 = $billing->recordPayment((int) $invoice['id'], ['amount' => 100000.0]);
        $this->assertEquals(50000.0, (float) $res1['amount_due']);
        $this->assertSame('partially_paid', $res1['payment_status']);

        // Pembayaran Rp 70.000 (melebihi sisa Rp 50.000) -> harus ditolak
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/tidak boleh melebihi sisa tagihan/i');
        $billing->recordPayment((int) $invoice['id'], ['amount' => 70000.0]);
    }

    public function testBillingCancelInvoiceVoidsAccruedFeeAllocations(): void
    {
        $billing = new BillingService($this->db, $this->tenantContext);

        $invoice = $billing->createInvoice([
            'invoice_number' => 'INV/2026/200',
            'issue_date' => date('Y-m-d'),
            'items' => [
                ['item_name' => 'Tindakan Medis', 'item_type' => 'service', 'quantity' => 1, 'unit_price' => 200000.0],
            ],
        ]);
        $invId = (int) $invoice['id'];

        // Simulasikan alokasi fee dokter accrued
        $this->db->statement("
            INSERT INTO tariff_fee_allocations (tenant_id, invoice_id, item_id, component_name, amount, status)
            VALUES (1, ?, 99, 'Jasa Dokter', 100000.0, 'accrued')
        ", [$invId]);

        $allocBefore = $this->db->selectOne("SELECT status FROM tariff_fee_allocations WHERE invoice_id = ?", [$invId]);
        $this->assertSame('accrued', $allocBefore['status']);

        // Batalkan invoice
        $billing->cancelInvoice($invId, 'Pasien batal tindakan');

        // Pastikan alokasi fee dokter otomatis dibatalkan (bukan accrued lagi)
        $allocAfter = $this->db->selectOne("SELECT status FROM tariff_fee_allocations WHERE invoice_id = ?", [$invId]);
        $this->assertSame('cancelled', $allocAfter['status']);
    }

    public function testScheduleCallNextTicketAtomicClaimAvoidsDuplicateDoctorCalls(): void
    {
        $schedule = new ScheduleService($this->db, $this->tenantContext);

        // Buat 2 tiket antrean
        $t1 = $schedule->issueQueueTicket(['prefix' => 'A', 'service_name' => 'Poli Umum', 'client_name' => 'Pasien 1']);
        $t2 = $schedule->issueQueueTicket(['prefix' => 'A', 'service_name' => 'Poli Umum', 'client_name' => 'Pasien 2']);

        $this->assertSame('waiting', $t1['status']);
        $this->assertSame('waiting', $t2['status']);

        // Dokter Poli 1 memanggil tiket pertama
        $called1 = $schedule->callNextTicket('A', roomId: 1, userId: 10);
        $this->assertNotNull($called1);
        $this->assertSame($t1['id'], $called1['id']);
        $this->assertSame('called', $called1['status']);

        // Dokter Poli 2 memanggil tiket berikutnya pada prefix yang sama
        $called2 = $schedule->callNextTicket('A', roomId: 2, userId: 20);
        $this->assertNotNull($called2);
        $this->assertSame($t2['id'], $called2['id']);
        $this->assertSame('called', $called2['status']);

        // Panggilan ketiga ketika antrean habis harus mengembalikan null
        $called3 = $schedule->callNextTicket('A', roomId: 1, userId: 10);
        $this->assertNull($called3);
    }
}
