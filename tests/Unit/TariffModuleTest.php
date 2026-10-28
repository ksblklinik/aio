<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Tariff\Contracts\TariffRegistryInterface;
use App\Modules\Tariff\Services\TariffService;
use Core\Container;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TariffModuleTest extends TestCase
{
    private Connection $db;
    private Migrator $migrator;
    private TenantContext $tenantContext;
    private TariffService $tariffService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new Connection('sqlite::memory:');
        $this->migrator = new Migrator($this->db);
        $this->migrator->runMigrationsFrom(__DIR__ . '/../../database/migrations');

        $this->tenantContext = TenantContext::getInstance();
        $this->tenantContext->clear();

        $container = Container::getInstance();
        $container->instance(Connection::class, $this->db);
        $container->instance(TenantContext::class, $this->tenantContext);

        // Seed 2 Tenants
        $this->db->statement("INSERT INTO tenants (id, name, slug) VALUES (1, 'Klinik Medika Sejahtera', 'medika-sejahtera')");
        $this->db->statement("INSERT INTO tenants (id, name, slug) VALUES (2, 'Bengkel Prima Motor', 'prima-motor')");

        // Seed Users
        $this->db->statement("INSERT INTO users (id, name, email, password_hash) VALUES (1, 'Admin User', 'admin@example.com', 'secret')");

        // Seed Contacts in Tenant 1 (Pasien, Dokter, Penjamin Asuransi BPJS & Mandiri)
        $this->db->statement("INSERT INTO contacts (id, tenant_id, code, type, name, email, phone) VALUES (10, 1, 'CUST-01', 'customer', 'Budi Pasien', 'budi@pasien.com', '0811111')");
        $this->db->statement("INSERT INTO contacts (id, tenant_id, code, type, name, email, phone) VALUES (20, 1, 'VND-01', 'vendor', 'BPJS Kesehatan', 'bpjs@kesehatan.go.id', '1500400')");
        $this->db->statement("INSERT INTO contacts (id, tenant_id, code, type, name, email, phone) VALUES (21, 1, 'VND-02', 'vendor', 'Asuransi Mandiri Inhealth', 'inhealth@mandiri.co.id', '0215555')");
        $this->db->statement("INSERT INTO contacts (id, tenant_id, code, type, name, email, phone) VALUES (30, 1, 'EMP-01', 'employee', 'dr. Andika Sp.PD', 'andika@dokter.com', '0822222')");
        $this->db->statement("INSERT INTO contacts (id, tenant_id, code, type, name, email, phone) VALUES (31, 1, 'EMP-02', 'employee', 'dr. Siti Sp.A', 'siti@dokter.com', '0833333')");

        // Seed Items in Tenant 1 (Layanan & Obat)
        $this->db->statement("INSERT INTO items (id, tenant_id, code, name, type, selling_price, is_active) VALUES (101, 1, 'SRV-001', 'Konsultasi Dokter Spesialis', 'service', 150000.0, 1)");
        $this->db->statement("INSERT INTO items (id, tenant_id, code, name, type, selling_price, is_active) VALUES (102, 1, 'SRV-002', 'USG Abdomen 2D', 'service', 250000.0, 1)");
        $this->db->statement("INSERT INTO items (id, tenant_id, code, name, type, selling_price, is_active) VALUES (201, 1, 'MED-001', 'Amoxicillin 500mg', 'goods', 12000.0, 1)");

        // Seed Invoices for testing allocations
        $this->db->statement("INSERT INTO invoices (id, tenant_id, invoice_number, contact_id, contact_name, status, issue_date, subtotal, grand_total, amount_due) VALUES (1001, 1, 'INV/2026/00001', 10, 'Budi Pasien', 'issued', '2026-10-28', 400000.0, 400000.0, 400000.0)");

        $this->tariffService = new TariffService($this->db, $this->tenantContext);
        $container->instance(TariffRegistryInterface::class, $this->tariffService);
    }

    public function testCreateAndListPriceBooksWithDefaultToggle(): void
    {
        $this->tenantContext->setTenantId('1');

        // 1. Buat Buku Tarif Reguler (Default)
        $regularBook = $this->tariffService->createPriceBook([
            'code' => 'REGULAR',
            'name' => 'Tarif Pasien Umum / Reguler',
            'is_default' => 1
        ]);
        $this->assertEquals('REGULAR', $regularBook['code']);
        $this->assertEquals(1, $regularBook['is_default']);

        // 2. Buat Buku Tarif BPJS
        $bpjsBook = $this->tariffService->createPriceBook([
            'code' => 'BPJS',
            'name' => 'Tarif BPJS Kesehatan',
            'payer_contact_id' => 20,
            'is_default' => 0
        ]);
        $this->assertEquals('BPJS', $bpjsBook['code']);
        $this->assertEquals(0, $bpjsBook['is_default']);

        // 3. Buat Buku Tarif Asuransi VIP dan ubah jadi default
        $vipBook = $this->tariffService->createPriceBook([
            'code' => 'VIP_INSURANCE',
            'name' => 'Tarif Asuransi Mandiri VIP',
            'payer_contact_id' => 21,
            'is_default' => 1
        ]);
        $this->assertEquals(1, $vipBook['is_default']);

        // Default lama (REGULAR) harus otomatis menjadi 0
        $refetchedRegular = $this->tariffService->getPriceBook($regularBook['id']);
        $this->assertEquals(0, $refetchedRegular['is_default']);

        // List dan pastikan search filter berfungsi
        $books = $this->tariffService->listPriceBooks(['search' => 'BPJS']);
        $this->assertCount(1, $books);
        $this->assertEquals('BPJS', $books[0]['code']);
    }

    public function testSetRatesAndResolvePriceWithTieringAndFallback(): void
    {
        $this->tenantContext->setTenantId('1');

        $bpjsBook = $this->tariffService->createPriceBook([
            'code' => 'BPJS',
            'name' => 'Tarif BPJS'
        ]);

        // Rate standar untuk konsultasi spesialis
        $this->tariffService->setRate($bpjsBook['id'], 101, 100000.0, [
            'coverage_type' => 'fixed',
            'coverage_amount' => 100000.0,
            'patient_copay' => 0.0
        ]);

        // Rate khusus kamar VIP untuk konsultasi spesialis (misal ada tambahan)
        $this->tariffService->setRate($bpjsBook['id'], 101, 160000.0, [
            'room_type' => 'vip',
            'coverage_type' => 'fixed',
            'coverage_amount' => 100000.0,
            'patient_copay' => 60000.0
        ]);

        // 1. Resolve untuk kamar standar
        $resolvedStandard = $this->tariffService->resolvePrice(101, $bpjsBook['id'], 1.0);
        $this->assertEquals(100000.0, $resolvedStandard['unit_price']);
        $this->assertEquals(100000.0, $resolvedStandard['coverage_amount']);
        $this->assertEquals(0.0, $resolvedStandard['patient_copay']);

        // 2. Resolve untuk kamar VIP
        $resolvedVip = $this->tariffService->resolvePrice(101, $bpjsBook['id'], 1.0, ['room_type' => 'vip']);
        $this->assertEquals(160000.0, $resolvedVip['unit_price']);
        $this->assertEquals(100000.0, $resolvedVip['coverage_amount']);
        $this->assertEquals(60000.0, $resolvedVip['patient_copay']);

        // 3. Fallback: item tanpa tarif di buku tarif harus fallback ke base_price katalog
        $resolvedFallback = $this->tariffService->resolvePrice(102, $bpjsBook['id'], 2.0);
        $this->assertEquals(250000.0, $resolvedFallback['unit_price']);
        $this->assertEquals(500000.0, $resolvedFallback['subtotal']);
        $this->assertEquals(0.0, $resolvedFallback['coverage_amount']);
        $this->assertEquals(500000.0, $resolvedFallback['total_copay']);
        $this->assertFalse($resolvedFallback['is_custom_rate']);
    }

    public function testCoverageTypesCalculations(): void
    {
        $this->tenantContext->setTenantId('1');

        $insBook = $this->tariffService->createPriceBook([
            'code' => 'INSURANCE_COPAY',
            'name' => 'Asuransi Co-Payment'
        ]);

        // Item 102 (USG Abdomen base rate 200.000) dengan coverage 80%
        $this->tariffService->setRate($insBook['id'], 102, 200000.0, [
            'coverage_type' => 'percentage',
            'coverage_amount' => 80.0 // 80% dicover asuransi
        ]);

        $resolved = $this->tariffService->resolvePrice(102, $insBook['id'], 1.0);
        $this->assertEquals(200000.0, $resolved['unit_price']);
        $this->assertEquals(160000.0, $resolved['coverage_amount']); // 80% dari 200k
        $this->assertEquals(40000.0, $resolved['patient_copay']);   // 20% sisa

        // Item 201 (Obat) dengan cap limit coverage max 10.000 (harga 15.000)
        $this->tariffService->setRate($insBook['id'], 201, 15000.0, [
            'coverage_type' => 'cap_limit',
            'coverage_amount' => 10000.0
        ]);

        $resolvedMed = $this->tariffService->resolvePrice(201, $insBook['id'], 2.0);
        $this->assertEquals(15000.0, $resolvedMed['unit_price']);
        $this->assertEquals(10000.0, $resolvedMed['coverage_amount']);
        $this->assertEquals(5000.0, $resolvedMed['patient_copay']);
        $this->assertEquals(20000.0, $resolvedMed['total_coverage']);
        $this->assertEquals(10000.0, $resolvedMed['total_copay']);
    }

    public function testSplitRulesValidationAndPercentageCap(): void
    {
        $this->tenantContext->setTenantId('1');

        // Buat aturan bagi hasil untuk Konsultasi Dokter Spesialis (Item 101)
        // 1. Jasa Medis Dokter: 60%
        $rule1 = $this->tariffService->createSplitRule([
            'item_id' => 101,
            'name' => 'Jasa Medis Dokter Spesialis',
            'recipient_type' => 'performer',
            'split_type' => 'percentage',
            'split_value' => 60.0,
            'target_account_code' => '2104'
        ]);
        $this->assertEquals(60.0, $rule1['split_value']);

        // 2. Jasa Sarana Fasilitas: 30%
        $rule2 = $this->tariffService->createSplitRule([
            'item_id' => 101,
            'name' => 'Jasa Sarana Klinik',
            'recipient_type' => 'facility',
            'split_type' => 'percentage',
            'split_value' => 30.0,
            'target_account_code' => '4102'
        ]);
        $this->assertEquals(30.0, $rule2['split_value']);

        // 3. Coba tambah aturan Biaya SIM / Admin: 15% (Total 60 + 30 + 15 = 105% -> HARUS ERROR)
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Total persentase aturan bagi hasil tidak boleh melebihi 100%");

        $this->tariffService->createSplitRule([
            'item_id' => 101,
            'name' => 'Biaya Administrasi & SIM',
            'recipient_type' => 'organization',
            'split_type' => 'percentage',
            'split_value' => 15.0
        ]);
    }

    public function testCalculateSplitsAndDynamicPerformer(): void
    {
        $this->tenantContext->setTenantId('1');

        // Setup rules valid untuk Item 101 (60% Dokter, 30% Sarana, 10% Admin)
        $this->tariffService->createSplitRule([
            'item_id' => 101,
            'name' => 'Jasa Medis Dokter',
            'recipient_type' => 'performer',
            'split_type' => 'percentage',
            'split_value' => 60.0
        ]);
        $this->tariffService->createSplitRule([
            'item_id' => 101,
            'name' => 'Jasa Sarana Klinik',
            'recipient_type' => 'facility',
            'split_type' => 'percentage',
            'split_value' => 30.0
        ]);
        $this->tariffService->createSplitRule([
            'item_id' => 101,
            'name' => 'Biaya Administrasi',
            'recipient_type' => 'organization',
            'split_type' => 'percentage',
            'split_value' => 10.0
        ]);

        // Kalkulasi splits pada total tagihan Rp 150.000 dengan dokter pelaksana dr. Andika (Contact ID: 30)
        $splits = $this->tariffService->calculateSplits(101, 150000.0, null, 30);
        $this->assertCount(3, $splits);

        // Jasa Dokter 60% dari 150.000 = 90.000, penerima contact_id = 30
        $this->assertEquals('Jasa Medis Dokter', $splits[0]['component_name']);
        $this->assertEquals(90000.0, $splits[0]['amount']);
        $this->assertEquals(30, $splits[0]['beneficiary_contact_id']);

        // Jasa Sarana 30% dari 150.000 = 45.000
        $this->assertEquals('Jasa Sarana Klinik', $splits[1]['component_name']);
        $this->assertEquals(45000.0, $splits[1]['amount']);

        // Admin 10% dari 150.000 = 15.000
        $this->assertEquals('Biaya Administrasi', $splits[2]['component_name']);
        $this->assertEquals(15000.0, $splits[2]['amount']);
    }

    public function testRecordInvoiceSplitsAndBeneficiarySummary(): void
    {
        $this->tenantContext->setTenantId('1');

        // Rules untuk Item 101 (Konsultasi Dokter)
        $this->tariffService->createSplitRule([
            'item_id' => 101,
            'name' => 'Jasa Medis Dokter',
            'recipient_type' => 'performer',
            'split_type' => 'percentage',
            'split_value' => 60.0
        ]);

        // Rekam alokasi fee saat invoice 1001 terbit untuk dr. Siti (Contact ID: 31)
        $allocations = $this->tariffService->recordInvoiceSplits(1001, [
            [
                'item_id' => 101,
                'quantity' => 1.0,
                'unit_price' => 150000.0,
                'total' => 150000.0,
                'performer_contact_id' => 31
            ]
        ]);

        $this->assertCount(1, $allocations);
        $this->assertEquals(90000.0, $allocations[0]['amount']);
        $this->assertEquals(31, $allocations[0]['beneficiary_contact_id']);
        $this->assertEquals('accrued', $allocations[0]['status']);

        // Periksa ringkasan honorarium dr. Siti
        $summary = $this->tariffService->getBeneficiarySummary(31);
        $this->assertEquals(1, $summary['total_allocations']);
        $this->assertEquals(90000.0, $summary['total_accrued']);
        $this->assertEquals(0.0, $summary['total_paid']);
    }

    public function testSettleBeneficiaryFees(): void
    {
        $this->tenantContext->setTenantId('1');

        $this->tariffService->createSplitRule([
            'item_id' => 101,
            'name' => 'Jasa Medis Dokter',
            'recipient_type' => 'performer',
            'split_type' => 'percentage',
            'split_value' => 60.0
        ]);

        // Rekam 2 transaksi alokasi fee untuk dr. Andika (Contact ID: 30)
        $allocations = $this->tariffService->recordInvoiceSplits(1001, [
            [
                'item_id' => 101,
                'quantity' => 1.0,
                'total' => 100000.0,
                'performer_contact_id' => 30
            ],
            [
                'item_id' => 101,
                'quantity' => 1.0,
                'total' => 200000.0,
                'performer_contact_id' => 30
            ]
        ]);

        $allocationIds = array_column($allocations, 'id');
        $this->assertCount(2, $allocationIds);

        // Lakukan settlement pembayaran jasa dokter
        $settlement = $this->tariffService->settleBeneficiaryFees(30, $allocationIds, 'PAY-DR-202610-01');

        $this->assertEquals('PAY-DR-202610-01', $settlement['settlement_reference']);
        $this->assertEquals(2, $settlement['settled_count']);
        $this->assertEquals(180000.0, $settlement['total_amount']); // (60% * 100k) + (60% * 200k) = 60k + 120k = 180k
        $this->assertEquals('paid', $settlement['status']);

        // Verifikasi ringkasan terupdate
        $summary = $this->tariffService->getBeneficiarySummary(30);
        $this->assertEquals(0.0, $summary['total_accrued']);
        $this->assertEquals(180000.0, $summary['total_paid']);

        // Mencoba mencairkan ulang ID yang sama harus melempar exception
        $this->expectException(InvalidArgumentException::class);
        $this->tariffService->settleBeneficiaryFees(30, $allocationIds);
    }

    public function testTenantIsolation(): void
    {
        // Tenant 1
        $this->tenantContext->setTenantId('1');
        $bookT1 = $this->tariffService->createPriceBook([
            'code' => 'T1_BOOK',
            'name' => 'Buku Tarif Tenant 1'
        ]);

        // Tenant 2
        $this->tenantContext->setTenantId('2');
        $booksT2 = $this->tariffService->listPriceBooks();
        $this->assertCount(0, $booksT2, 'Tenant 2 tidak boleh melihat buku tarif milik Tenant 1');

        $this->assertNull($this->tariffService->getPriceBook($bookT1['id']), 'Tenant 2 tidak boleh mengakses buku tarif Tenant 1 via ID');
    }

    public function testUpdateAndDeletePriceBook(): void
    {
        $this->tenantContext->setTenantId('1');

        $book = $this->tariffService->createPriceBook([
            'code' => 'TEMP_BOOK',
            'name' => 'Buku Sementara'
        ]);

        $updated = $this->tariffService->updatePriceBook($book['id'], [
            'name' => 'Buku Diperbarui',
            'description' => 'Deskripsi baru'
        ]);
        $this->assertEquals('Buku Diperbarui', $updated['name']);
        $this->assertEquals('Deskripsi baru', $updated['description']);

        $deleted = $this->tariffService->deletePriceBook($book['id']);
        $this->assertTrue($deleted);
        $this->assertNull($this->tariffService->getPriceBook($book['id']));
    }

    public function testDeleteRateAndSplitRule(): void
    {
        $this->tenantContext->setTenantId('1');

        $book = $this->tariffService->createPriceBook([
            'code' => 'RATE_TEST',
            'name' => 'Rate Test Book'
        ]);

        $rate = $this->tariffService->setRate($book['id'], 101, 75000.0);
        $this->assertEquals(75000.0, $rate['base_rate']);

        $deleteRate = $this->tariffService->deleteRate((int)$rate['id']);
        $this->assertTrue($deleteRate);
        $this->assertNull($this->tariffService->getRate($book['id'], 101));

        $rule = $this->tariffService->createSplitRule([
            'name' => 'Komisi Sementara',
            'recipient_type' => 'performer',
            'split_type' => 'fixed_amount',
            'split_value' => 20000.0
        ]);

        $deleteRule = $this->tariffService->deleteSplitRule((int)$rule['id']);
        $this->assertTrue($deleteRule);
    }
}

