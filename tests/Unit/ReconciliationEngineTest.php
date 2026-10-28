<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Container;
use Core\Database\Connection;
use Core\Reconciliation\Auditors\CryptographicSealAuditor;
use Core\Reconciliation\Auditors\FinanceBillingAuditor;
use Core\Reconciliation\Auditors\InventoryStockAuditor;
use Core\Reconciliation\Auditors\TariffSplitAuditor;
use Core\Reconciliation\Auditors\TenantReferentialAuditor;
use Core\Reconciliation\ReconciliationManager;
use Core\Reconciliation\ReconciliationManagerInterface;
use PHPUnit\Framework\TestCase;

class ReconciliationEngineTest extends TestCase
{
    private string $tempDir;
    private string $tempDbPath;
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aio_reconcile_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->tempDbPath = $this->tempDir . DIRECTORY_SEPARATOR . 'test_reconcile.sqlite';
        $this->db = new Connection("sqlite:{$this->tempDbPath}");

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        $this->db->close();
        $this->deleteRecursive($this->tempDir);
        parent::tearDown();
    }

    private function createSchema(): void
    {
        // 1. Tenants table
        $this->db->statement("
            CREATE TABLE tenants (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL
            );
        ");
        $this->db->statement("INSERT INTO tenants (id, name) VALUES (1, 'Klinik Utama'), (2, 'Klinik Cabang');");

        // 2. Contacts
        $this->db->statement("
            CREATE TABLE contacts (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                name TEXT NOT NULL
            );
        ");

        // 3. Invoices & Payments & Items
        $this->db->statement("
            CREATE TABLE invoices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                invoice_number TEXT NOT NULL,
                grand_total REAL NOT NULL DEFAULT 0,
                amount_paid REAL NOT NULL DEFAULT 0,
                amount_due REAL NOT NULL DEFAULT 0,
                payment_status TEXT NOT NULL DEFAULT 'unpaid',
                updated_at TEXT
            );
        ");
        $this->db->statement("
            CREATE TABLE invoice_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                invoice_id INTEGER NOT NULL,
                subtotal REAL NOT NULL DEFAULT 0
            );
        ");
        $this->db->statement("
            CREATE TABLE invoice_payments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                invoice_id INTEGER NOT NULL,
                amount REAL NOT NULL DEFAULT 0,
                payment_date TEXT
            );
        ");

        // 4. Financial Accounts & Transactions
        $this->db->statement("
            CREATE TABLE financial_accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                code TEXT NOT NULL DEFAULT 'ACC-001',
                name TEXT NOT NULL,
                initial_balance REAL NOT NULL DEFAULT 0,
                current_balance REAL NOT NULL DEFAULT 0,
                updated_at TEXT
            );
        ");
        $this->db->statement("
            CREATE TABLE financial_transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                source_account_id INTEGER,
                target_account_id INTEGER,
                amount REAL NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT 'completed'
            );
        ");

        // 5. Items, Movements, Locations, Batches
        $this->db->statement("
            CREATE TABLE items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                code TEXT NOT NULL DEFAULT 'ITM-001',
                name TEXT NOT NULL,
                current_stock REAL NOT NULL DEFAULT 0,
                track_inventory INTEGER NOT NULL DEFAULT 1,
                updated_at TEXT
            );
        ");
        $this->db->statement("
            CREATE TABLE inventory_movements (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                item_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                quantity REAL NOT NULL DEFAULT 0,
                stock_before REAL DEFAULT 0,
                stock_after REAL DEFAULT 0
            );
        ");
        $this->db->statement("
            CREATE TABLE inventory_stock_locations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                item_id INTEGER NOT NULL,
                room_id INTEGER,
                quantity REAL NOT NULL DEFAULT 0,
                updated_at TEXT
            );
        ");
        $this->db->statement("
            CREATE TABLE item_batches (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                item_id INTEGER NOT NULL,
                batch_number TEXT NOT NULL,
                current_quantity REAL NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT 'active',
                updated_at TEXT
            );
        ");

        // 6. Tariff allocations & rules
        $this->db->statement("
            CREATE TABLE tariff_fee_allocations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                invoice_id INTEGER NOT NULL,
                amount REAL NOT NULL DEFAULT 0,
                component_name TEXT,
                status TEXT NOT NULL DEFAULT 'pending'
            );
        ");
        $this->db->statement("
            CREATE TABLE tariff_split_rules (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                item_id INTEGER,
                price_book_id INTEGER,
                name TEXT NOT NULL,
                split_type TEXT NOT NULL,
                split_value REAL NOT NULL DEFAULT 0
            );
        ");

        // 7. Document seals
        $this->db->statement("
            CREATE TABLE document_seals (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                verification_token TEXT NOT NULL DEFAULT 'tok_test',
                document_type TEXT NOT NULL,
                document_number TEXT NOT NULL,
                title TEXT NOT NULL DEFAULT 'Test Doc',
                payload_snapshot TEXT NOT NULL,
                canonical_hash TEXT NOT NULL,
                signature TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'valid'
            );
        ");

        // 8. Reconciliation Audit Logs
        $this->db->statement("
            CREATE TABLE reconciliation_audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                pillar TEXT NOT NULL,
                severity TEXT NOT NULL,
                entity_type TEXT NOT NULL,
                entity_id TEXT NOT NULL,
                description TEXT NOT NULL,
                expected_value TEXT,
                actual_value TEXT,
                resolved INTEGER NOT NULL DEFAULT 0,
                resolved_at TEXT,
                resolution_notes TEXT,
                created_at TEXT NOT NULL
            );
        ");
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

    public function testFinanceBillingAuditorDetectsAndSyncsDrifts(): void
    {
        $auditor = new FinanceBillingAuditor($this->db);

        // 1. Insert invoice with desynchronized amount_paid
        $this->db->statement("
            INSERT INTO invoices (tenant_id, invoice_number, grand_total, amount_paid, amount_due, payment_status)
            VALUES (1, 'INV-001', 500000, 200000, 300000, 'partially_paid')
        ");
        $invId = (int) $this->db->lastInsertId();

        // Actual payments sum to 500,000 (fully paid)
        $this->db->statement("INSERT INTO invoice_payments (tenant_id, invoice_id, amount) VALUES (1, ?, 300000)", [$invId]);
        $this->db->statement("INSERT INTO invoice_payments (tenant_id, invoice_id, amount) VALUES (1, ?, 200000)", [$invId]);

        // 2. Insert financial account with desynchronized balance
        $this->db->statement("
            INSERT INTO financial_accounts (tenant_id, name, initial_balance, current_balance)
            VALUES (1, 'Kasir Tunai', 1000000, 1000000)
        ");
        $accId = (int) $this->db->lastInsertId();

        // Actual ledger has +500,000 inflow, -100,000 outflow => Expected balance is 1,400,000
        $this->db->statement("
            INSERT INTO financial_transactions (tenant_id, type, source_account_id, amount, status)
            VALUES (1, 'inflow', ?, 500000, 'completed')
        ", [$accId]);
        $this->db->statement("
            INSERT INTO financial_transactions (tenant_id, type, source_account_id, amount, status)
            VALUES (1, 'outflow', ?, 100000, 'completed')
        ", [$accId]);

        // Run Audit
        $audit = $auditor->audit(1);
        $this->assertFalse($audit->isBalanced);
        $this->assertCount(2, $audit->discrepancies);

        // Run Reconcile (Dry Run first)
        $dryResult = $auditor->reconcile(1, dryRun: true);
        $this->assertEquals(2, $dryResult['resolved']);

        // Verify DB unchanged in dry run
        $invBefore = $this->db->selectOne("SELECT amount_paid FROM invoices WHERE id = ?", [$invId]);
        $this->assertEquals(200000, (float) $invBefore['amount_paid']);

        // Run Live Reconcile
        $liveResult = $auditor->reconcile(1, dryRun: false);
        $this->assertEquals(2, $liveResult['resolved']);

        // Verify DB updated
        $invAfter = $this->db->selectOne("SELECT amount_paid, amount_due, payment_status FROM invoices WHERE id = ?", [$invId]);
        $this->assertEquals(500000, (float) $invAfter['amount_paid']);
        $this->assertEquals(0, (float) $invAfter['amount_due']);
        $this->assertEquals('paid', $invAfter['payment_status']);

        $accAfter = $this->db->selectOne("SELECT current_balance FROM financial_accounts WHERE id = ?", [$accId]);
        $this->assertEquals(1400000, (float) $accAfter['current_balance']);

        // Audit again: must now be 100% balanced
        $auditAfter = $auditor->audit(1);
        $this->assertTrue($auditAfter->isBalanced);
    }

    public function testInventoryStockAuditorDetectsPhantomStockAndSyncs(): void
    {
        $auditor = new InventoryStockAuditor($this->db);

        // Insert item with phantom stock (recorded: 100, actual ledger: 40)
        $this->db->statement("
            INSERT INTO items (tenant_id, name, current_stock, track_inventory)
            VALUES (1, 'Paracetamol 500mg', 100, 1)
        ");
        $itemId = (int) $this->db->lastInsertId();

        // Ledger: +60 IN, -20 OUT = 40
        $this->db->statement("INSERT INTO inventory_movements (tenant_id, item_id, type, quantity) VALUES (1, ?, 'in', 60)", [$itemId]);
        $this->db->statement("INSERT INTO inventory_movements (tenant_id, item_id, type, quantity) VALUES (1, ?, 'out', 20)", [$itemId]);

        // Audit
        $audit = $auditor->audit(1);
        $this->assertFalse($audit->isBalanced);
        $this->assertCount(1, $audit->discrepancies);
        $this->assertEquals(100.0, (float) $audit->discrepancies[0]->actualValue);
        $this->assertEquals(40.0, (float) $audit->discrepancies[0]->expectedValue);

        // Reconcile
        $reconcileRes = $auditor->reconcile(1, false);
        $this->assertEquals(1, $reconcileRes['resolved']);

        $itemAfter = $this->db->selectOne("SELECT current_stock FROM items WHERE id = ?", [$itemId]);
        $this->assertEquals(40, (float) $itemAfter['current_stock']);

        // Audit again: balanced
        $this->assertTrue($auditor->audit(1)->isBalanced);
    }

    public function testTariffSplitAuditorDetectsOverAllocation(): void
    {
        $auditor = new TariffSplitAuditor($this->db);

        // Insert invoice of 100,000
        $this->db->statement("
            INSERT INTO invoices (tenant_id, invoice_number, grand_total)
            VALUES (1, 'INV-SPLIT-01', 100000)
        ");
        $invId = (int) $this->db->lastInsertId();

        // Allocations sum to 130,000 (exceeds invoice total!)
        $this->db->statement("INSERT INTO tariff_fee_allocations (tenant_id, invoice_id, amount, component_name) VALUES (1, ?, 80000, 'Jasa Dokter')", [$invId]);
        $this->db->statement("INSERT INTO tariff_fee_allocations (tenant_id, invoice_id, amount, component_name) VALUES (1, ?, 50000, 'Jasa Sarana')", [$invId]);

        // Insert rule with >100% split
        $this->db->statement("
            INSERT INTO tariff_split_rules (tenant_id, name, split_type, split_value)
            VALUES (1, 'Rule Rusak', 'percentage', 120.0)
        ");

        $audit = $auditor->audit(1);
        $this->assertFalse($audit->isBalanced);
        $this->assertCount(2, $audit->discrepancies);
    }

    public function testCryptographicSealAuditorDetectsTamperedData(): void
    {
        $key = 'aio-universal-secret-key-32chars!!';
        $auditor = new CryptographicSealAuditor($this->db, $key);

        $payload = json_encode(['doc' => 'Surat Keterangan Sehat', 'patient' => 'Budi']);
        $canonicalHash = hash('sha256', $payload);
        $tenantSecret = hash_hmac('sha256', '1', $key);
        $validSignature = hash_hmac('sha256', $canonicalHash, $tenantSecret);

        // 1. Insert valid seal
        $this->db->statement("
            INSERT INTO document_seals (tenant_id, document_type, document_number, payload_snapshot, canonical_hash, signature, status)
            VALUES (1, 'medical_certificate', 'MED-001', ?, ?, ?, 'valid')
        ", [$payload, $canonicalHash, $validSignature]);

        $audit1 = $auditor->audit(1);
        $this->assertTrue($audit1->isBalanced);

        // 2. Tamper with the payload directly in DB
        $tamperedPayload = json_encode(['doc' => 'Surat Keterangan Sakit PALSU', 'patient' => 'Budi']);
        $this->db->statement("
            UPDATE document_seals SET payload_snapshot = ? WHERE document_number = 'MED-001'
        ", [$tamperedPayload]);

        $audit2 = $auditor->audit(1);
        $this->assertFalse($audit2->isBalanced);
        $this->assertCount(1, $audit2->discrepancies);
        $this->assertEquals('critical', $audit2->discrepancies[0]->severity);
    }

    public function testTenantReferentialAuditorDetectsLeakageAndOrphans(): void
    {
        $auditor = new TenantReferentialAuditor($this->db);

        // 1. Parent invoice belongs to tenant 1
        $this->db->statement("
            INSERT INTO invoices (id, tenant_id, invoice_number)
            VALUES (10, 1, 'INV-TENANT-01')
        ");

        // 2. Child invoice_item wrongly assigned to tenant 2 (Cross-Tenant Leakage)
        $this->db->statement("
            INSERT INTO invoice_items (id, tenant_id, invoice_id, subtotal)
            VALUES (101, 2, 10, 50000)
        ");

        // 3. Orphan item_batch pointing to non-existent item #999
        $this->db->statement("
            INSERT INTO item_batches (id, tenant_id, item_id, batch_number, current_quantity)
            VALUES (201, 1, 999, 'BATCH-GHOST', 50)
        ");

        $audit = $auditor->audit();
        $this->assertFalse($audit->isBalanced);
        $this->assertGreaterThanOrEqual(2, count($audit->discrepancies));

        // Reconcile: cross-tenant leakage should be auto-synced
        $res = $auditor->reconcile(null, false);
        $this->assertGreaterThanOrEqual(1, $res['resolved']);

        // Verify child item now has tenant_id = 1
        $itemAfter = $this->db->selectOne("SELECT tenant_id FROM invoice_items WHERE id = 101");
        $this->assertEquals(1, (int) $itemAfter['tenant_id']);
    }

    public function testReconciliationManagerFullAuditAndSyncLogging(): void
    {
        $manager = new ReconciliationManager($this->db);

        // Create initial clean audit
        $report = $manager->audit();
        $this->assertTrue($report->isHealthy);
        $this->assertCount(5, $report->pillars);

        // Introduce a drift in financial accounts
        $this->db->statement("
            INSERT INTO financial_accounts (id, tenant_id, name, initial_balance, current_balance)
            VALUES (5, 1, 'Bank Operasional', 2000000, 2000000)
        ");
        $this->db->statement("
            INSERT INTO financial_transactions (tenant_id, type, source_account_id, amount, status)
            VALUES (1, 'inflow', 5, 500000, 'completed')
        ");

        // Audit again: should detect drift
        $dirtyReport = $manager->audit();
        $this->assertFalse($dirtyReport->isHealthy);
        $this->assertGreaterThan(0, $dirtyReport->discrepancyCount);

        // Run live reconciliation
        $result = $manager->reconcile();
        $this->assertTrue($result->success);
        $this->assertGreaterThan(0, $result->resolvedCount);

        // Check that reconciliation_audit_logs recorded the audit and resolution
        $pending = $manager->getPendingDiscrepancies(1);
        $this->assertIsArray($pending);

        $logs = $this->db->selectAll("SELECT * FROM reconciliation_audit_logs WHERE entity_id = '5'");
        $this->assertNotEmpty($logs);
        $this->assertEquals(1, (int) $logs[0]['resolved']);
    }

    public function testGlobalReconcileHelper(): void
    {
        $container = Container::getInstance();
        $container->singleton(ReconciliationManagerInterface::class, function () {
            return new ReconciliationManager($this->db);
        });

        $this->assertTrue(function_exists('reconcile'));
        $instance = reconcile();
        $this->assertInstanceOf(ReconciliationManagerInterface::class, $instance);
    }
}
