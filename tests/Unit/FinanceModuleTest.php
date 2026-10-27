<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Finance\Contracts\FinanceRegistryInterface;
use App\Modules\Finance\Services\FinanceService;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use Core\Ledger\LedgerManager;
use Core\Security\Encrypter;
use Core\Sequence\SequenceManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FinanceModuleTest extends TestCase
{
    private Connection $db;
    private Migrator $migrator;
    private TenantContext $tenantContext;
    private LedgerManager $ledger;
    private FinanceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['APP_KEY'] = 'test-secret-key-32chars-for-tests!';

        $this->db = new Connection('sqlite::memory:');
        $this->migrator = new Migrator($this->db);
        $this->migrator->runMigrationsFrom(__DIR__ . '/../../database/migrations');

        $this->tenantContext = TenantContext::getInstance();
        $this->tenantContext->clear();

        $container = \Core\Container::getInstance();
        $container->instance(Connection::class, $this->db);
        $container->instance(TenantContext::class, $this->tenantContext);
        $container->instance(Encrypter::class, new Encrypter($_ENV['APP_KEY']));
        $container->instance(SequenceManager::class, new SequenceManager($this->db));

        $this->ledger = new LedgerManager($this->db, $this->tenantContext);
        $container->instance(LedgerManager::class, $this->ledger);

        // Buat data tenant 1 & 2
        $this->db->statement("INSERT INTO tenants (id, name, slug) VALUES (1, 'Klinik Alpha', 'alpha')");
        $this->db->statement("INSERT INTO tenants (id, name, slug) VALUES (2, 'Toko Beta', 'beta')");

        $this->service = new FinanceService($this->db, $this->tenantContext, $this->ledger);
        $container->instance(FinanceRegistryInterface::class, $this->service);
    }

    protected function tearDown(): void
    {
        $this->tenantContext->clear();
        parent::tearDown();
    }

    public function testCreateFinancialAccountAndEncryptAccountNumber(): void
    {
        $this->tenantContext->setTenantId('1');

        $accountId = $this->service->createAccount([
            'code' => 'BCA-01',
            'name' => 'Rekening BCA Operasional',
            'type' => 'bank',
            'bank_name' => 'BCA',
            'account_number' => '5432109876',
            'initial_balance' => 5000000.0,
        ]);

        $this->assertGreaterThan(0, $accountId);

        // Ambil via domain model / service
        $account = $this->service->getAccount($accountId);
        $this->assertNotNull($account);
        $this->assertSame('BCA-01', $account['code']);
        $this->assertSame('5432109876', $account['account_number']); // Didekripsi otomatis
        $this->assertEquals(5000000.0, $account['current_balance']);

        // Verifikasi bahwa di database tersimpan terenkripsi
        $raw = $this->db->selectOne("SELECT account_number FROM financial_accounts WHERE id = ?", [$accountId]);
        $this->assertNotEquals('5432109876', $raw['account_number']);
        $this->assertSame('5432109876', decrypt($raw['account_number']));

        // Verifikasi saldo awal dicatat di LedgerManager
        $ledgerBalance = $this->ledger->getBalance('financial_account', (string) $accountId, 1);
        $this->assertEquals(5000000.0, $ledgerBalance);
    }

    public function testDefaultCategoriesAutoSeeding(): void
    {
        $this->tenantContext->setTenantId('1');

        $categories = $this->service->listCategories();
        $this->assertNotEmpty($categories);
        $this->assertGreaterThanOrEqual(10, count($categories));

        $incomeCategories = $this->service->listCategories('income');
        $this->assertNotEmpty($incomeCategories);
        $this->assertSame('income', $incomeCategories[0]['type']);

        $expenseCategories = $this->service->listCategories('expense');
        $this->assertNotEmpty($expenseCategories);
        $this->assertSame('expense', $expenseCategories[0]['type']);
    }

    public function testRecordInflowMutationAndDoubleEntryLedger(): void
    {
        $this->tenantContext->setTenantId('1');

        $accId = $this->service->createAccount([
            'code' => 'KAS-01',
            'name' => 'Kas Tunai Kasir',
            'type' => 'cash',
            'initial_balance' => 1000000.0,
        ]);

        // Buat kontak pembayar
        $contactId = (int) $this->db->insert('contacts', [
            'tenant_id' => 1,
            'type' => 'individual',
            'code' => 'CNT-001',
            'name' => 'Pasien Budi',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $categories = $this->service->listCategories('income');
        $catId = $categories[0]['id'];

        $trxId = $this->service->recordInflow(
            accountId: $accId,
            amount: 250000.0,
            categoryId: $catId,
            contactId: $contactId,
            description: 'Pembayaran Konsultasi Pasien Budi',
            referenceNumber: 'INV-2026-001'
        );

        $this->assertGreaterThan(0, $trxId);

        // Verifikasi saldo akun kas bertambah
        $this->assertEquals(1250000.0, $this->service->getBalance($accId));

        // Verifikasi mutasi transaksi
        $trx = $this->service->getTransaction($trxId);
        $this->assertNotNull($trx);
        $this->assertSame('inflow', $trx['type']);
        $this->assertSame('completed', $trx['status']);
        $this->assertSame($contactId, $trx['contact_id']);
        $this->assertEquals(250000.0, $trx['amount']);
        $this->assertStringStartsWith('TRX/', $trx['transaction_number']);

        // Verifikasi saldo di LedgerManager
        $ledgerAccBalance = $this->ledger->getBalance('financial_account', (string) $accId, 1);
        $this->assertEquals(1250000.0, $ledgerAccBalance);

        $ledgerCatBalance = $this->ledger->getBalance('financial_category', (string) $catId, 1);
        $this->assertEquals(250000.0, $ledgerCatBalance);
    }

    public function testRecordOutflowMutationAndInsufficientBalanceGuard(): void
    {
        $this->tenantContext->setTenantId('1');

        $accId = $this->service->createAccount([
            'code' => 'KAS-02',
            'name' => 'Kas Operasional',
            'type' => 'cash',
            'initial_balance' => 500000.0,
        ]);

        $categories = $this->service->listCategories('expense');
        $catId = $categories[0]['id'];

        // Coba outflow melebihi saldo kas -> harus throw RuntimeException
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Saldo kas tidak mencukupi');
        $this->service->recordOutflow(
            accountId: $accId,
            amount: 750000.0,
            categoryId: $catId,
            description: 'Beli ATK Melebihi Saldo'
        );
    }

    public function testRecordOutflowSuccess(): void
    {
        $this->tenantContext->setTenantId('1');

        $accId = $this->service->createAccount([
            'code' => 'KAS-03',
            'name' => 'Petty Cash',
            'type' => 'cash',
            'initial_balance' => 1000000.0,
        ]);

        $categories = $this->service->listCategories('expense');
        $catId = $categories[0]['id'];

        $trxId = $this->service->recordOutflow(
            accountId: $accId,
            amount: 300000.0,
            categoryId: $catId,
            description: 'Beli Kertas HVS & Tinta Printer',
            receiptPath: '/storage/receipts/nota_atk.jpg'
        );

        $this->assertGreaterThan(0, $trxId);
        $this->assertEquals(700000.0, $this->service->getBalance($accId));

        $trx = $this->service->getTransaction($trxId);
        $this->assertSame('/storage/receipts/nota_atk.jpg', $trx['receipt_file_path']);
        $this->assertSame('completed', $trx['status']);
    }

    public function testTransferBetweenAccounts(): void
    {
        $this->tenantContext->setTenantId('1');

        $sourceId = $this->service->createAccount([
            'code' => 'BCA-SRC',
            'name' => 'BCA Induk',
            'type' => 'bank',
            'initial_balance' => 10000000.0,
        ]);

        $targetId = $this->service->createAccount([
            'code' => 'KAS-TGT',
            'name' => 'Kas Cabang',
            'type' => 'cash',
            'initial_balance' => 1000000.0,
        ]);

        $trxId = $this->service->transfer(
            sourceAccountId: $sourceId,
            targetAccountId: $targetId,
            amount: 3000000.0,
            description: 'Dropping kas cabang mingguan'
        );

        $this->assertGreaterThan(0, $trxId);

        // Verifikasi saldo kedua akun
        $this->assertEquals(7000000.0, $this->service->getBalance($sourceId));
        $this->assertEquals(4000000.0, $this->service->getBalance($targetId));

        // Verifikasi mutasi transaksi
        $trx = $this->service->getTransaction($trxId);
        $this->assertSame('transfer', $trx['type']);
        $this->assertSame($sourceId, $trx['source_account_id']);
        $this->assertSame($targetId, $trx['target_account_id']);
    }

    public function testDisbursementApprovalWorkflow(): void
    {
        $this->tenantContext->setTenantId('1');

        $accId = $this->service->createAccount([
            'code' => 'KAS-MGR',
            'name' => 'Kas Besar',
            'type' => 'cash',
            'initial_balance' => 5000000.0,
        ]);

        $categories = $this->service->listCategories('expense');
        $catId = $categories[0]['id'];

        // 1. Pengajuan pengeluaran kas yang butuh approval
        $trxId = $this->service->recordOutflow(
            accountId: $accId,
            amount: 2000000.0,
            categoryId: $catId,
            description: 'Pengadaan Meja Resepsionis Baru',
            requiresApproval: true
        );

        $trx = $this->service->getTransaction($trxId);
        $this->assertSame('pending_approval', $trx['status']);

        // Saldo belum terpotong!
        $this->assertEquals(5000000.0, $this->service->getBalance($accId));

        // 2. Setujui transaksi oleh Manager (User ID: 99)
        $this->service->approveOutflow($trxId, 99);

        $approvedTrx = $this->service->getTransaction($trxId);
        $this->assertSame('completed', $approvedTrx['status']);
        $this->assertSame(99, $approvedTrx['approved_by']);
        $this->assertNotNull($approvedTrx['approved_at']);

        // Saldo sekarang sudah terpotong
        $this->assertEquals(3000000.0, $this->service->getBalance($accId));
    }

    public function testDisbursementRejectionWorkflow(): void
    {
        $this->tenantContext->setTenantId('1');

        $accId = $this->service->createAccount([
            'code' => 'KAS-REJ',
            'name' => 'Kas Pengujian Tolak',
            'type' => 'cash',
            'initial_balance' => 2000000.0,
        ]);

        $categories = $this->service->listCategories('expense');
        $catId = $categories[0]['id'];

        $trxId = $this->service->recordOutflow(
            accountId: $accId,
            amount: 1500000.0,
            categoryId: $catId,
            description: 'Pembelian Sofa Ruang Tamu',
            requiresApproval: true
        );

        // Tolak transaksi oleh Manager (User ID: 88)
        $this->service->rejectOutflow($trxId, 88, 'Anggaran operasional belum disetujui');

        $rejectedTrx = $this->service->getTransaction($trxId);
        $this->assertSame('cancelled', $rejectedTrx['status']);
        $this->assertStringContainsString('Anggaran operasional belum disetujui', $rejectedTrx['description']);

        // Saldo tetap utuh
        $this->assertEquals(2000000.0, $this->service->getBalance($accId));
    }

    public function testFinancialReportsGeneration(): void
    {
        $this->tenantContext->setTenantId('1');

        $accId = $this->service->createAccount([
            'code' => 'ACC-RPT',
            'name' => 'Rekening Laporan',
            'type' => 'bank',
            'initial_balance' => 10000000.0,
        ]);

        $incomeCat = $this->service->listCategories('income')[0]['id'];
        $expenseCat = $this->service->listCategories('expense')[0]['id'];

        $today = date('Y-m-d');

        // Catat Inflow Rp 4.000.000
        $this->service->recordInflow($accId, 4000000.0, $incomeCat, description: 'Pendapatan Proyek A', transactionDate: $today);

        // Catat Outflow Rp 1.500.000
        $this->service->recordOutflow($accId, 1500000.0, $expenseCat, description: 'Gaji Freelancer', transactionDate: $today);

        // 1. Uji Cash Flow Statement
        $cashFlow = $this->service->getCashFlowStatement($today, $today);
        $this->assertEquals(4000000.0, $cashFlow['total_inflow']);
        $this->assertEquals(1500000.0, $cashFlow['total_outflow']);
        $this->assertEquals(2500000.0, $cashFlow['net_cash_flow']);

        // 2. Uji Profit & Loss Statement
        $pnl = $this->service->getProfitLossStatement($today, $today);
        $this->assertEquals(4000000.0, $pnl['total_revenue']);
        $this->assertEquals(1500000.0, $pnl['total_expense']);
        $this->assertEquals(2500000.0, $pnl['net_profit']);

        // 3. Uji Account Ledger Statement (Rekening Koran)
        $ledgerStmt = $this->service->getAccountLedgerStatement($accId, $today, $today);
        $this->assertEquals(10000000.0, $ledgerStmt['beginning_balance']);
        $this->assertCount(2, $ledgerStmt['entries']);
        $this->assertEquals(12500000.0, $ledgerStmt['ending_balance']);
    }

    public function testMultiTenantDataIsolation(): void
    {
        // Tenant 1
        $this->tenantContext->setTenantId('1');
        $accTenant1 = $this->service->createAccount([
            'code' => 'KAS-T1',
            'name' => 'Kas Tenant 1',
            'initial_balance' => 500000.0,
        ]);

        // Tenant 2
        $this->tenantContext->setTenantId('2');
        $accTenant2 = $this->service->createAccount([
            'code' => 'KAS-T2',
            'name' => 'Kas Tenant 2',
            'initial_balance' => 900000.0,
        ]);

        // Tenant 2 tidak boleh dapat melihat akun Tenant 1
        $this->assertNull($this->service->getAccount($accTenant1));
        $this->assertNotNull($this->service->getAccount($accTenant2));

        $tenant2Accounts = $this->service->listAccounts();
        $this->assertCount(1, $tenant2Accounts);
        $this->assertSame('KAS-T2', $tenant2Accounts[0]['code']);
    }

    public function testGlobalFinanceHelper(): void
    {
        $this->tenantContext->setTenantId('1');
        $this->assertInstanceOf(FinanceRegistryInterface::class, finance());
    }
}
