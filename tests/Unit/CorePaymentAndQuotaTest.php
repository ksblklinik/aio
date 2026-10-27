<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Billing\QuotaManager;
use Core\Database\Connection;
use Core\Database\TenantContext;
use Core\Foundation\Application;
use Core\Ledger\LedgerManager;
use Core\Payment\KaseraPaymentDriver;
use Core\Payment\ManualTransferDriver;
use Core\Payment\MockPaymentDriver;
use Core\Payment\PaymentManager;
use Core\Payment\PaymentRequest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CorePaymentAndQuotaTest extends TestCase
{
    private Application $app;
    private Connection $db;
    private LedgerManager $ledger;
    private PaymentManager $payment;
    private QuotaManager $quota;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Application(dirname(__DIR__, 2));
        $this->db = new Connection('sqlite::memory:');

        TenantContext::getInstance()->setTenantId('101', ['name' => 'Klinik Sehat Sejahtera']);

        // Inisialisasi skema tabel ledger
        $this->db->getPdo()->exec("
            CREATE TABLE IF NOT EXISTS ledgers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                account_type TEXT NOT NULL,
                account_id TEXT NOT NULL,
                currency TEXT NOT NULL DEFAULT 'IDR',
                balance REAL NOT NULL DEFAULT 0.0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(tenant_id, account_type, account_id)
            );

            CREATE TABLE IF NOT EXISTS ledger_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                ledger_id INTEGER NOT NULL,
                direction TEXT NOT NULL,
                amount REAL NOT NULL,
                balance_after REAL NOT NULL,
                reference_type TEXT,
                reference_id TEXT,
                description TEXT,
                metadata TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
        ");

        // Inisialisasi skema tabel payment & quota
        $this->db->getPdo()->exec("
            CREATE TABLE IF NOT EXISTS payment_transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                reference_no VARCHAR(64) UNIQUE NOT NULL,
                driver VARCHAR(32) NOT NULL,
                amount DECIMAL(15,2) NOT NULL,
                currency VARCHAR(3) DEFAULT 'IDR',
                status VARCHAR(20) DEFAULT 'pending',
                payment_method VARCHAR(32) NULL,
                customer_name VARCHAR(150) NULL,
                customer_email VARCHAR(150) NULL,
                customer_phone VARCHAR(32) NULL,
                gateway_reference VARCHAR(128) NULL,
                checkout_url TEXT NULL,
                metadata TEXT NULL,
                paid_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS tenant_entitlements (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                feature_key VARCHAR(64) NOT NULL,
                is_enabled INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(tenant_id, feature_key)
            );

            CREATE TABLE IF NOT EXISTS tenant_quotas (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                metric_key VARCHAR(64) NOT NULL,
                quota_limit INTEGER NOT NULL,
                current_usage INTEGER DEFAULT 0,
                period VARCHAR(20) DEFAULT 'monthly',
                period_start DATETIME NOT NULL,
                period_end DATETIME NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(tenant_id, metric_key)
            );
        ");

        $this->ledger = new LedgerManager($this->db);
        $this->payment = new PaymentManager($this->db, $this->ledger);
        $this->quota = new QuotaManager($this->db);
    }

    // ========================================================
    // 1. PAYMENT GATEWAY DRIVERS TESTS
    // ========================================================

    public function testMockPaymentDriverChargeAndWebhook(): void
    {
        $driver = new MockPaymentDriver();
        $req = new PaymentRequest(
            tenantId: 101,
            referenceNo: 'INV-2026-001',
            amount: 150000.0,
            customerName: 'Budi Santoso',
            customerEmail: 'budi@example.com'
        );

        $res = $driver->charge($req);
        $this->assertTrue($res->isSuccess);
        $this->assertEquals('pending', $res->status);
        $this->assertNotEmpty($res->checkoutUrl);
        $this->assertNotEmpty($res->qrString);

        // Simulasi webhook
        $payload = $driver->generateWebhookPayload('INV-2026-001', 'paid', 150000.0);
        $webhook = $driver->verifyWebhook($payload);

        $this->assertTrue($webhook->isValid);
        $this->assertEquals('INV-2026-001', $webhook->referenceNo);
        $this->assertEquals('paid', $webhook->status);
        $this->assertEquals(150000.0, $webhook->amount);
    }

    public function testManualTransferDriver(): void
    {
        $driver = new ManualTransferDriver([
            'bank_name' => 'BCA',
            'account_number' => '987654321',
        ]);

        $req = new PaymentRequest(
            tenantId: 101,
            referenceNo: 'DONASI-2026-005',
            amount: 500000.0
        );

        $res = $driver->charge($req);
        $this->assertTrue($res->isSuccess);
        $this->assertEquals('pending', $res->status);
        $this->assertNull($res->checkoutUrl);
        $this->assertEquals('BCA', $res->metadata['bank_name']);
        $this->assertEquals('987654321', $res->metadata['account_number']);

        $webhook = $driver->verifyWebhook(['reference_no' => 'DONASI-2026-005', 'status' => 'paid', 'amount' => 500000.0]);
        $this->assertTrue($webhook->isValid);
        $this->assertEquals('paid', $webhook->status);
    }

    public function testKaseraPaymentDriverWebhookSignatureAndMockHttp(): void
    {
        $secret = 'test_secret_kasera';
        $driver = new KaseraPaymentDriver(['webhook_secret' => $secret]);

        $payload = [
            'order_id' => 'KAS-777',
            'transaction_status' => 'settlement',
            'gross_amount' => 250000.0,
            'payment_type' => 'qris',
        ];

        // Webhook tanpa signature valid
        $badResult = $driver->verifyWebhook($payload, ['X-Kasera-Signature' => 'invalid_signature']);
        $this->assertFalse($badResult->isValid);
        $this->assertStringContainsString('Invalid', (string) $badResult->errorMessage);

        // Webhook dengan signature valid
        $validSignature = hash_hmac('sha256', (string) json_encode($payload), $secret);
        $goodResult = $driver->verifyWebhook($payload, ['X-Kasera-Signature' => $validSignature]);
        $this->assertTrue($goodResult->isValid);
        $this->assertEquals('KAS-777', $goodResult->referenceNo);
        $this->assertEquals('paid', $goodResult->status);
        $this->assertEquals(250000.0, $goodResult->amount);
    }

    // ========================================================
    // 2. PAYMENT MANAGER & LEDGER RECONCILIATION TESTS
    // ========================================================

    public function testPaymentManagerChargeAndDatabaseRecord(): void
    {
        $req = new PaymentRequest(
            tenantId: 101,
            referenceNo: 'ORD-999',
            amount: 75000.0,
            paymentMethod: 'qris',
            customerName: 'Ahmad'
        );

        $res = $this->payment->charge($req, 'mock');
        $this->assertTrue($res->isSuccess);

        $txn = $this->payment->getTransaction('ORD-999');
        $this->assertNotNull($txn);
        $this->assertEquals('ORD-999', $txn['reference_no']);
        $this->assertEquals(75000.0, (float) $txn['amount']);
        $this->assertEquals('pending', $txn['status']);
        $this->assertEquals('mock', $txn['driver']);
    }

    public function testPaymentManagerRejectsZeroOrNegativeAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $req = new PaymentRequest(
            tenantId: 101,
            referenceNo: 'ORD-BAD',
            amount: -100.0
        );
        $this->payment->charge($req);
    }

    public function testPaymentManagerWebhookWithAtomicLedgerReconciliation(): void
    {
        $req = new PaymentRequest(
            tenantId: 101,
            referenceNo: 'BILL-100',
            amount: 200000.0,
            metadata: [
                'ledger_reconciliation' => true,
                'ledger_account_type' => 'driver_wallet',
                'ledger_account_id' => 'driver_12',
                'ledger_direction' => 'credit',
            ]
        );

        $this->payment->charge($req, 'mock');

        // Sebelum webhook, saldo ledger masih kosong/0
        $balanceBefore = $this->ledger->getBalance('driver_wallet', 'driver_12', 101);
        $this->assertEquals(0.0, $balanceBefore);

        // Eksekusi webhook sukses dari gateway
        $driver = $this->payment->driver('mock');
        /** @var MockPaymentDriver $driver */
        $payload = $driver->generateWebhookPayload('BILL-100', 'paid', 200000.0);

        $result = $this->payment->handleWebhook($payload);
        $this->assertTrue($result->isValid);
        $this->assertEquals('paid', $result->status);

        // Verifikasi status transaksi di database
        $txn = $this->payment->getTransaction('BILL-100');
        $this->assertEquals('paid', $txn['status']);
        $this->assertNotNull($txn['paid_at']);

        // Verifikasi saldo di LedgerManager otomatis bertambah (Rekonsiliasi atomik)
        $balanceAfter = $this->ledger->getBalance('driver_wallet', 'driver_12', 101);
        $this->assertEquals(200000.0, $balanceAfter);

        // Uji Idempotency: Jika webhook dikirim ulang, saldo tidak boleh berlipat ganda
        $repeatResult = $this->payment->handleWebhook($payload);
        $this->assertTrue($repeatResult->isValid);
        $balanceAfterRepeat = $this->ledger->getBalance('driver_wallet', 'driver_12', 101);
        $this->assertEquals(200000.0, $balanceAfterRepeat);
    }

    public function testPaymentManagerRefundAndLedgerReversal(): void
    {
        $req = new PaymentRequest(
            tenantId: 101,
            referenceNo: 'BILL-REFUND',
            amount: 100000.0,
            metadata: [
                'ledger_reconciliation' => true,
                'ledger_account_type' => 'merchant_balance',
                'ledger_account_id' => 'store_88',
                'ledger_direction' => 'credit',
            ]
        );

        $this->payment->charge($req, 'mock');
        $driver = $this->payment->driver('mock');
        /** @var MockPaymentDriver $driver */
        $payload = $driver->generateWebhookPayload('BILL-REFUND', 'paid', 100000.0);
        $this->payment->handleWebhook($payload);

        $this->assertEquals(100000.0, $this->ledger->getBalance('merchant_balance', 'store_88', 101));

        // Lakukan refund penuh
        $refundOk = $this->payment->refund('BILL-REFUND', 100000.0, 'Customer cancellation');
        $this->assertTrue($refundOk);

        $txn = $this->payment->getTransaction('BILL-REFUND');
        $this->assertEquals('refunded', $txn['status']);

        // Saldo ledger harus berbalik menjadi 0
        $this->assertEquals(0.0, $this->ledger->getBalance('merchant_balance', 'store_88', 101));
    }

    // ========================================================
    // 3. TENANT FEATURE ENTITLEMENTS (GATING) TESTS
    // ========================================================

    public function testFeatureEntitlementsGating(): void
    {
        // Default belum diset: false
        $this->assertFalse($this->quota->can('ai_copilot', 101));

        // Set izin fitur
        $this->quota->setFeature('ai_copilot', true, 101);
        $this->assertTrue($this->quota->can('ai_copilot', 101));

        // Nonaktifkan fitur
        $this->quota->setFeature('ai_copilot', false, 101);
        $this->assertFalse($this->quota->can('ai_copilot', 101));

        // Isolasi multi-tenant: tenant 202 tidak terpengaruh tenant 101
        $this->quota->setFeature('export_excel', true, 101);
        $this->assertTrue($this->quota->can('export_excel', 101));
        $this->assertFalse($this->quota->can('export_excel', 202));
    }

    public function testAllFeaturesListing(): void
    {
        $this->quota->setFeature('module_canvassing', true, 101);
        $this->quota->setFeature('custom_fields', true, 101);
        $this->quota->setFeature('beta_reports', false, 101);

        $features = $this->quota->features(101);
        $this->assertCount(3, $features);
        $this->assertTrue($features['module_canvassing']);
        $this->assertTrue($features['custom_fields']);
        $this->assertFalse($features['beta_reports']);
    }

    // ========================================================
    // 4. USAGE QUOTA METERING TESTS
    // ========================================================

    public function testQuotaConsumptionAndRemaining(): void
    {
        // Batasi 5 invoice per bulan
        $this->quota->setLimit('monthly_invoices', 5, 'monthly', 101);

        $this->assertEquals(5, $this->quota->remaining('monthly_invoices', 101));
        $this->assertFalse($this->quota->isExceeded('monthly_invoices', 101));

        // Konsumsi 2
        $this->assertTrue($this->quota->consume('monthly_invoices', 2, 101));
        $this->assertEquals(3, $this->quota->remaining('monthly_invoices', 101));

        // Konsumsi 3 lagi (mencapai batas 5)
        $this->assertTrue($this->quota->consume('monthly_invoices', 3, 101));
        $this->assertEquals(0, $this->quota->remaining('monthly_invoices', 101));
        $this->assertTrue($this->quota->isExceeded('monthly_invoices', 101));

        // Konsumsi berikutnya harus DITOLAK (false) dan usage tidak bertambah
        $this->assertFalse($this->quota->consume('monthly_invoices', 1, 101));
        $this->assertEquals(0, $this->quota->remaining('monthly_invoices', 101));

        // Reset kuota manual
        $this->quota->reset('monthly_invoices', 101);
        $this->assertEquals(5, $this->quota->remaining('monthly_invoices', 101));
        $this->assertFalse($this->quota->isExceeded('monthly_invoices', 101));
    }

    public function testUnlimitedQuotaHandling(): void
    {
        // -1 untuk unlimited
        $this->quota->setLimit('api_calls', -1, 'monthly', 101);

        $this->assertEquals(PHP_INT_MAX, $this->quota->remaining('api_calls', 101));
        $this->assertFalse($this->quota->isExceeded('api_calls', 101));

        $this->assertTrue($this->quota->consume('api_calls', 1000, 101));
        $this->assertFalse($this->quota->isExceeded('api_calls', 101));
    }

    public function testQuotaMultiTenantIsolation(): void
    {
        $this->quota->setLimit('driver_seats', 2, 'lifetime', 101);
        $this->quota->setLimit('driver_seats', 10, 'lifetime', 202);

        $this->quota->consume('driver_seats', 2, 101);
        $this->assertTrue($this->quota->isExceeded('driver_seats', 101));

        // Tenant 202 masih memiliki sisa 10
        $this->assertFalse($this->quota->isExceeded('driver_seats', 202));
        $this->assertEquals(10, $this->quota->remaining('driver_seats', 202));
    }

    public function testQuotaUsageReport(): void
    {
        $this->quota->setLimit('wa_broadcasts', 50, 'monthly', 101);
        $this->quota->consume('wa_broadcasts', 12, 101);

        $report = $this->quota->usage('wa_broadcasts', 101);
        $this->assertEquals('wa_broadcasts', $report['metric']);
        $this->assertEquals(50, $report['limit']);
        $this->assertEquals(12, $report['current']);
        $this->assertEquals(38, $report['remaining']);
        $this->assertFalse($report['is_exceeded']);
        $this->assertEquals('monthly', $report['period']);
        $this->assertNotNull($report['period_end']);
    }

    // ========================================================
    // 5. GLOBAL HELPERS TESTS
    // ========================================================

    public function testGlobalHelpers(): void
    {
        $this->assertInstanceOf(PaymentManager::class, payment());
        $this->assertInstanceOf(MockPaymentDriver::class, payment('mock'));
        $this->assertInstanceOf(QuotaManager::class, quota());
        $this->assertInstanceOf(LedgerManager::class, ledger());
    }
}
