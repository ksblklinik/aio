<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Billing\Contracts\BillingRegistryInterface;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Contact\Contracts\ContactRegistryInterface;
use App\Modules\Contact\Services\ContactService;
use App\Modules\Finance\Contracts\FinanceRegistryInterface;
use App\Modules\Finance\Services\FinanceService;
use App\Modules\Inventory\Contracts\InventoryRegistryInterface;
use App\Modules\Inventory\Services\InventoryService;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use Core\Sequence\SequenceManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BillingModuleTest extends TestCase
{
    private Connection $db;
    private Migrator $migrator;
    private TenantContext $tenantContext;
    private BillingService $billing;
    private InventoryService $inventory;
    private FinanceService $finance;
    private ContactService $contact;

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

        // Buat 2 tenant untuk uji multi-tenant
        $this->db->statement("INSERT INTO tenants (id, name, slug) VALUES (1, 'Klinik & Apotek Sehat', 'klinik')");
        $this->db->statement("INSERT INTO tenants (id, name, slug) VALUES (2, 'Bengkel Motor Kilat', 'bengkel')");

        $this->contact = new ContactService($this->db, $this->tenantContext);
        $container->instance(ContactRegistryInterface::class, $this->contact);

        $this->finance = new FinanceService($this->db, $this->tenantContext);
        $container->instance(FinanceRegistryInterface::class, $this->finance);

        $this->inventory = new InventoryService($this->db, $this->tenantContext);
        $container->instance(InventoryRegistryInterface::class, $this->inventory);

        $this->billing = new BillingService($this->db, $this->tenantContext);
        $container->instance(BillingRegistryInterface::class, $this->billing);
    }

    protected function tearDown(): void
    {
        $this->tenantContext->clear();
        parent::tearDown();
    }

    public function testCanCreateDraftInvoiceWithLineItemsAndCorrectTotals(): void
    {
        $this->tenantContext->setTenantId('1');

        $invoice = $this->billing->createInvoice([
            'contact_name' => 'Budi Santoso',
            'type' => 'invoice',
            'status' => 'draft',
            'notes' => 'Invoice pembelian obat dan konsultasi',
            'items' => [
                [
                    'item_name' => 'Amoxicillin 500mg',
                    'item_type' => 'goods',
                    'quantity' => 2,
                    'unit_price' => 15000,
                    'discount' => 2000,
                    'tax_rate' => 10,
                ],
                [
                    'item_name' => 'Jasa Konsultasi Dokter',
                    'item_type' => 'service',
                    'quantity' => 1,
                    'unit_price' => 50000,
                    'discount' => 0,
                    'tax_rate' => 0,
                ],
            ],
        ]);

        $this->assertNotNull($invoice);
        $this->assertStringStartsWith('INV/', $invoice['invoice_number']);
        $this->assertSame('draft', $invoice['status']);
        $this->assertSame('unpaid', $invoice['payment_status']);
        $this->assertSame('Budi Santoso', $invoice['contact_name']);

        // Item 1: (2 * 15000) - 2000 = 28000 + 10% (2800) = 30800
        // Item 2: 1 * 50000 = 50000
        // Subtotal: 30000 + 50000 = 80000
        // Discount total: 2000
        // Tax total: 2800
        // Grand total: 80800
        $this->assertEquals(80000.0, (float) $invoice['subtotal']);
        $this->assertEquals(2000.0, (float) $invoice['discount_total']);
        $this->assertEquals(2800.0, (float) $invoice['tax_total']);
        $this->assertEquals(80800.0, (float) $invoice['grand_total']);
        $this->assertEquals(80800.0, (float) $invoice['amount_due']);
        $this->assertEquals(0.0, (float) $invoice['amount_paid']);
        $this->assertCount(2, $invoice['items']);
    }

    public function testCannotIssueInvoiceIfPhysicalItemStockInsufficient(): void
    {
        $this->tenantContext->setTenantId('1');

        // Buat item obat dengan stok fisik = 5
        $itemId = $this->inventory->createItem([
            'name' => 'Paracetamol Drop',
            'type' => 'goods',
            'selling_price' => 25000,
            'initial_stock' => 5,
        ]);

        // Buat invoice draft yang memesan 10 botol (melebihi stok 5)
        $invoice = $this->billing->createInvoice([
            'contact_name' => 'Pasien Anak',
            'status' => 'draft',
            'items' => [
                [
                    'item_id' => $itemId,
                    'quantity' => 10,
                    'unit_price' => 25000,
                ],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Stok tidak mencukupi untuk item 'Paracetamol Drop'");

        // Percobaan issue invoice harus gagal melempar exception
        $this->billing->issueInvoice((int) $invoice['id']);
    }

    public function testIssuingInvoiceDeductsInventoryStockForGoodsAndLeavesServiceAlone(): void
    {
        $this->tenantContext->setTenantId('1');

        $itemId = $this->inventory->createItem([
            'name' => 'Vitamin C 500mg',
            'type' => 'goods',
            'selling_price' => 10000,
            'initial_stock' => 20,
        ]);

        $serviceId = $this->inventory->createItem([
            'name' => 'Tindakan Injeksi',
            'type' => 'service',
            'selling_price' => 35000,
        ]);

        $invoice = $this->billing->createInvoice([
            'contact_name' => 'Dewi Lestari',
            'status' => 'draft',
            'items' => [
                [
                    'item_id' => $itemId,
                    'quantity' => 4,
                    'unit_price' => 10000,
                ],
                [
                    'item_id' => $serviceId,
                    'quantity' => 1,
                    'unit_price' => 35000,
                ],
            ],
        ]);

        // Terbitkan invoice
        $issued = $this->billing->issueInvoice((int) $invoice['id']);
        $this->assertSame('issued', $issued['status']);

        // Stok barang fisik harus terpotong 20 - 4 = 16
        $this->assertEquals(16.0, $this->inventory->getStock($itemId));
    }

    public function testRecordPartialAndFullPaymentUpdatesInvoiceStatusAndRecordsFinanceCashFlow(): void
    {
        $this->tenantContext->setTenantId('1');

        // Setup rekening kas di modul Finance
        $kasId = $this->finance->createAccount([
            'code' => 'KAS-KASIR',
            'name' => 'Kasir Utama',
            'type' => 'cash',
            'initial_balance' => 500000.0,
        ]);

        $invoice = $this->billing->createInvoice([
            'contact_name' => 'PT Sumber Farma',
            'status' => 'issued',
            'items' => [
                [
                    'item_name' => 'Paket Medical Checkup',
                    'item_type' => 'service',
                    'quantity' => 1,
                    'unit_price' => 300000,
                ],
            ],
        ]);

        $this->assertEquals(300000.0, (float) $invoice['grand_total']);
        $this->assertSame('unpaid', $invoice['payment_status']);

        // 1. Pembayaran cicilan 1: Rp 100.000
        $step1 = $this->billing->recordPayment((int) $invoice['id'], [
            'amount' => 100000.0,
            'account_id' => $kasId,
            'payment_method' => 'cash',
            'reference_note' => 'Cicilan pertama',
        ]);

        $this->assertSame('partially_paid', $step1['status']);
        $this->assertSame('partially_paid', $step1['payment_status']);
        $this->assertEquals(100000.0, (float) $step1['amount_paid']);
        $this->assertEquals(200000.0, (float) $step1['amount_due']);
        $this->assertCount(1, $step1['payments']);

        // Saldo kasir bertambah 500.000 + 100.000 = 600.000
        $this->assertEquals(600000.0, $this->finance->getBalance($kasId));

        // 2. Pembayaran pelunasan sisa: Rp 200.000
        $step2 = $this->billing->recordPayment((int) $invoice['id'], [
            'amount' => 200000.0,
            'account_id' => $kasId,
            'payment_method' => 'bank_transfer',
            'reference_note' => 'Pelunasan transfer',
        ]);

        $this->assertSame('paid', $step2['status']);
        $this->assertSame('paid', $step2['payment_status']);
        $this->assertEquals(300000.0, (float) $step2['amount_paid']);
        $this->assertEquals(0.0, (float) $step2['amount_due']);
        $this->assertCount(2, $step2['payments']);

        // Saldo kasir bertambah menjadi 800.000
        $this->assertEquals(800000.0, $this->finance->getBalance($kasId));
    }

    public function testCannotOverpayInvoice(): void
    {
        $this->tenantContext->setTenantId('1');

        $invoice = $this->billing->createInvoice([
            'contact_name' => 'Siti Nurhaliza',
            'status' => 'issued',
            'items' => [
                [
                    'item_name' => 'Obat Batuk',
                    'item_type' => 'goods',
                    'quantity' => 1,
                    'unit_price' => 50000,
                ],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tidak boleh melebihi sisa tagihan');

        // Bayar 60.000 padahal tagihan 50.000
        $this->billing->recordPayment((int) $invoice['id'], [
            'amount' => 60000.0,
            'payment_method' => 'cash',
        ]);
    }

    public function testQuickCheckoutAtomicallyCreatesInvoiceDeductsStockAndRecordsFinance(): void
    {
        $this->tenantContext->setTenantId('1');

        $kasId = $this->finance->createAccount([
            'code' => 'KAS-POS',
            'name' => 'Laci Kasir POS',
            'type' => 'cash',
            'initial_balance' => 100000.0,
        ]);

        $itemId = $this->inventory->createItem([
            'name' => 'Koyo Herbal',
            'type' => 'goods',
            'selling_price' => 12500,
            'initial_stock' => 10,
        ]);

        // Kasir cepat: beli 2 koyo herbal = 25.000, bayar tunai 50.000
        $checkout = $this->billing->quickCheckout([
            'contact_name' => 'Pelanggan Kasir Tunai',
            'account_id' => $kasId,
            'payment_amount' => 50000.0,
            'payment_method' => 'cash',
            'items' => [
                [
                    'item_id' => $itemId,
                    'quantity' => 2,
                    'unit_price' => 12500,
                ],
            ],
        ]);

        $this->assertSame('paid', $checkout['status']);
        $this->assertSame('paid', $checkout['payment_status']);
        $this->assertEquals(25000.0, (float) $checkout['grand_total']);
        $this->assertEquals(25000.0, (float) $checkout['amount_paid']);
        $this->assertEquals(0.0, (float) $checkout['amount_due']);
        $this->assertEquals(50000.0, (float) $checkout['tendered_amount']);
        $this->assertEquals(25000.0, (float) $checkout['change']); // Kembalian Rp 25.000

        // Stok berkurang 10 - 2 = 8
        $this->assertEquals(8.0, $this->inventory->getStock($itemId));

        // Kas bertambah sebesar penjualan riil (25.000), bukan 50.000 yang ada kembalian
        $this->assertEquals(125000.0, $this->finance->getBalance($kasId));
    }

    public function testCancellingIssuedInvoiceRevertsInventoryStock(): void
    {
        $this->tenantContext->setTenantId('1');

        $itemId = $this->inventory->createItem([
            'name' => 'Masker Medis Box',
            'type' => 'goods',
            'selling_price' => 30000,
            'initial_stock' => 15,
        ]);

        // Terbitkan invoice 3 box (stok sisa 12)
        $invoice = $this->billing->createInvoice([
            'contact_name' => 'Klinik Cabang',
            'status' => 'issued',
            'items' => [
                [
                    'item_id' => $itemId,
                    'quantity' => 3,
                    'unit_price' => 30000,
                ],
            ],
        ]);

        $this->assertEquals(12.0, $this->inventory->getStock($itemId));

        // Batalkan invoice
        $cancelled = $this->billing->cancelInvoice((int) $invoice['id'], 'Salah input order', true);
        $this->assertSame('cancelled', $cancelled['status']);
        $this->assertStringContainsString('Salah input order', $cancelled['notes']);

        // Stok masker harus kembali menjadi 15
        $this->assertEquals(15.0, $this->inventory->getStock($itemId));
    }

    public function testMultiTenantIsolationInBillingRegistry(): void
    {
        // Tenant 1 buat invoice
        $this->tenantContext->setTenantId('1');
        $inv1 = $this->billing->createInvoice([
            'contact_name' => 'Pasien Tenant 1',
            'items' => [
                ['item_name' => 'Obat T1', 'quantity' => 1, 'unit_price' => 10000],
            ],
        ]);

        // Tenant 2 buat invoice
        $this->tenantContext->setTenantId('2');
        $inv2 = $this->billing->createInvoice([
            'contact_name' => 'Pelanggan Bengkel T2',
            'items' => [
                ['item_name' => 'Oli Mesin T2', 'quantity' => 1, 'unit_price' => 50000],
            ],
        ]);

        // Tenant 2 tidak boleh bisa melihat invoice milik Tenant 1
        $this->assertNull($this->billing->getInvoice((int) $inv1['id']));

        // Daftar invoice tenant 2 hanya berisi 1 invoice
        $listT2 = $this->billing->listInvoices();
        $this->assertCount(1, $listT2);
        $this->assertSame('Pelanggan Bengkel T2', $listT2[0]['contact_name']);
    }

    public function testBillingSummaryAggregatesTotalBilledReceivedAndDue(): void
    {
        $this->tenantContext->setTenantId('1');

        // Invoice 1: 100.000 (lunas)
        $inv1 = $this->billing->createInvoice([
            'contact_name' => 'Klien A',
            'status' => 'issued',
            'items' => [['item_name' => 'Jasa A', 'quantity' => 1, 'unit_price' => 100000]],
        ]);
        $this->billing->recordPayment((int) $inv1['id'], ['amount' => 100000.0]);

        // Invoice 2: 200.000 (cicil 50.000, sisa 150.000)
        $inv2 = $this->billing->createInvoice([
            'contact_name' => 'Klien B',
            'status' => 'issued',
            'items' => [['item_name' => 'Jasa B', 'quantity' => 1, 'unit_price' => 200000]],
        ]);
        $this->billing->recordPayment((int) $inv2['id'], ['amount' => 50000.0]);

        // Invoice 3: 50.000 (unpaid)
        $this->billing->createInvoice([
            'contact_name' => 'Klien C',
            'status' => 'issued',
            'items' => [['item_name' => 'Jasa C', 'quantity' => 1, 'unit_price' => 50000]],
        ]);

        $summary = $this->billing->getBillingSummary();

        // Total Billed: 100.000 + 200.000 + 50.000 = 350.000
        $this->assertEquals(350000.0, $summary['total_billed']);
        // Total Received: 100.000 + 50.000 = 150.000
        $this->assertEquals(150000.0, $summary['total_received']);
        // Total Outstanding: 0 + 150.000 + 50.000 = 200.000
        $this->assertEquals(200000.0, $summary['total_outstanding']);
        $this->assertEquals(3.0, $summary['count_invoices']);
        $this->assertEquals(1.0, $summary['count_paid']);
        $this->assertEquals(1.0, $summary['count_unpaid']);
    }

    public function testInvoiceDeductsStockFromSpecificLocationAndFefoBatch(): void
    {
        $this->tenantContext->setTenantId('1');

        $locId = (int) $this->db->insert('facilities_locations', [
            'tenant_id' => 1,
            'code' => 'LOC-FARMASI',
            'name' => 'Klinik Farmasi',
            'type' => 'clinic',
            'is_active' => 1,
        ]);
        $roomId = (int) $this->db->insert('facilities_rooms', [
            'tenant_id' => 1,
            'location_id' => $locId,
            'code' => 'GUD-OBAT',
            'name' => 'Gudang Obat Utama',
            'floor' => '1',
            'type' => 'storage',
            'capacity' => 500,
            'is_active' => 1,
        ]);

        $itemId = $this->inventory->createItem([
            'name' => 'Paracetamol 500mg Strip',
            'type' => 'goods',
            'selling_price' => 10000,
            'initial_stock' => 0,
        ]);

        // Batch 1: Expire lebih awal (2026-11-01) - 10 strip
        $b1 = $this->inventory->createBatch($itemId, 'BATCH-NOV', '2026-11-01');
        $this->inventory->recordStockMovement(
            itemId: $itemId,
            quantity: 10,
            type: 'in',
            notes: 'Penerimaan batch nov',
            roomId: $roomId,
            batchId: $b1
        );

        // Batch 2: Expire lebih lambat (2026-12-01) - 10 strip
        $b2 = $this->inventory->createBatch($itemId, 'BATCH-DEC', '2026-12-01');
        $this->inventory->recordStockMovement(
            itemId: $itemId,
            quantity: 10,
            type: 'in',
            notes: 'Penerimaan batch dec',
            roomId: $roomId,
            batchId: $b2
        );

        $this->assertEquals(20.0, $this->inventory->getStock($itemId));
        $this->assertEquals(20.0, $this->inventory->getLocationStock($itemId, $roomId));

        // Terbitkan invoice 12 strip dengan room_id yang ditentukan
        $invoice = $this->billing->createInvoice([
            'contact_name' => 'Pasien BPJS 001',
            'status' => 'issued',
            'items' => [
                [
                    'item_id' => $itemId,
                    'item_name' => 'Paracetamol 500mg Strip',
                    'quantity' => 12,
                    'unit_price' => 10000,
                    'room_id' => $roomId,
                ],
            ],
        ]);

        $this->assertNotNull($invoice);
        // Sisa total stok harus 8
        $this->assertEquals(8.0, $this->inventory->getStock($itemId));

        // Verifikasi alokasi FEFO: Batch NOV (10) habis (0), Batch DEC (10) berkurang 2 (sisa 8)
        $batch1Data = $this->inventory->getBatch($b1);
        $batch2Data = $this->inventory->getBatch($b2);
        $this->assertEquals(0.0, (float) $batch1Data['current_quantity']);
        $this->assertEquals(8.0, (float) $batch2Data['current_quantity']);

        // Batalkan invoice -> stok dan batch harus kembali
        $this->billing->cancelInvoice((int) $invoice['id'], 'Retur obat', true);
        $this->assertEquals(20.0, $this->inventory->getStock($itemId));
    }

    public function testInvoiceFailsWhenRoomStockIsInsufficient(): void
    {
        $this->tenantContext->setTenantId('1');

        $locId = (int) $this->db->insert('facilities_locations', [
            'tenant_id' => 1,
            'code' => 'LOC-FARMASI-2',
            'name' => 'Klinik Farmasi 2',
            'type' => 'clinic',
            'is_active' => 1,
        ]);
        $roomId = (int) $this->db->insert('facilities_rooms', [
            'tenant_id' => 1,
            'location_id' => $locId,
            'code' => 'GUD-OBAT-2',
            'name' => 'Gudang Obat 2',
            'floor' => '1',
            'type' => 'storage',
            'capacity' => 500,
            'is_active' => 1,
        ]);

        $itemId = $this->inventory->createItem([
            'name' => 'Amoxicillin 500mg',
            'type' => 'goods',
            'selling_price' => 15000,
            'initial_stock' => 0,
        ]);

        // Simpan hanya 5 strip di ruangan ini
        $this->inventory->recordStockMovement(
            itemId: $itemId,
            quantity: 5,
            type: 'in',
            notes: 'Penerimaan obat',
            roomId: $roomId
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Stok tidak mencukupi untuk item 'Amoxicillin 500mg' (dibutuhkan 10.00 di ruangan/gudang ID $roomId).");

        $this->billing->createInvoice([
            'contact_name' => 'Pasien Umum',
            'status' => 'issued',
            'items' => [
                [
                    'item_id' => $itemId,
                    'item_name' => 'Amoxicillin 500mg',
                    'quantity' => 10,
                    'unit_price' => 15000,
                    'room_id' => $roomId,
                ],
            ],
        ]);
    }
}
