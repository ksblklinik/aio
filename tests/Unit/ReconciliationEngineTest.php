<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Container;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Reconciliation\Auditors\ClosedPeriodAuditor;
use Core\Reconciliation\Auditors\CryptographicSealAuditor;
use Core\Reconciliation\Auditors\DomainBoundaryAuditor;
use Core\Reconciliation\Auditors\FinanceBillingAuditor;
use Core\Reconciliation\Auditors\InventoryStockAuditor;
use Core\Reconciliation\Auditors\StateMonotonicityAuditor;
use Core\Reconciliation\Auditors\TariffSplitAuditor;
use Core\Reconciliation\Auditors\TenantReferentialAuditor;
use Core\Reconciliation\ReconciliationManager;
use Core\Reconciliation\ReconciliationManagerInterface;
use Tests\TestCase;

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
        // Anti-Schema Drift: Run all official core migrations
        $migrator = new Migrator($this->db);
        $migrator->runMigrationsFrom(dirname(__DIR__, 2) . '/database/migrations');

        // Seed initial tenants
        $this->db->statement("INSERT INTO tenants (id, name, slug) VALUES (1, 'Klinik Utama', 'klinik-utama'), (2, 'Klinik Cabang', 'klinik-cabang');");
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
            INSERT INTO invoices (tenant_id, invoice_number, contact_name, issue_date, grand_total, amount_paid, amount_due, payment_status)
            VALUES (1, 'INV-001', 'Pasien Test', '2026-10-29', 500000, 200000, 300000, 'partially_paid')
        ");
        $invId = (int) $this->db->lastInsertId();

        // Actual payments sum to 500,000 (fully paid)
        $this->db->statement("INSERT INTO invoice_payments (tenant_id, invoice_id, payment_number, payment_date, amount) VALUES (1, ?, 'PAY-001', '2026-10-29', 300000)", [$invId]);
        $this->db->statement("INSERT INTO invoice_payments (tenant_id, invoice_id, payment_number, payment_date, amount) VALUES (1, ?, 'PAY-002', '2026-10-29', 200000)", [$invId]);

        // 2. Insert financial account with desynchronized balance
        $this->db->statement("
            INSERT INTO financial_accounts (tenant_id, code, name, initial_balance, current_balance)
            VALUES (1, 'ACC-KASIR', 'Kasir Tunai', 1000000, 1000000)
        ");
        $accId = (int) $this->db->lastInsertId();

        // Actual ledger has +500,000 inflow, -100,000 outflow => Expected balance is 1,400,000
        $this->db->statement("
            INSERT INTO financial_transactions (tenant_id, transaction_number, description, transaction_date, type, source_account_id, amount, status)
            VALUES (1, 'TX-001', 'Penerimaan Kasir', '2026-10-29', 'inflow', ?, 500000, 'completed')
        ", [$accId]);
        $this->db->statement("
            INSERT INTO financial_transactions (tenant_id, transaction_number, description, transaction_date, type, source_account_id, amount, status)
            VALUES (1, 'TX-002', 'Operasional Kasir', '2026-10-29', 'outflow', ?, 100000, 'completed')
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

        // Insert item with recorded current_stock = 100, but movements sum = 70
        $this->db->statement("
            INSERT INTO items (tenant_id, code, name, current_stock, track_inventory)
            VALUES (1, 'PAR-500', 'Paracetamol 500mg', 100, 1)
        ");
        $itemId = (int) $this->db->lastInsertId();

        $this->db->statement("
            INSERT INTO inventory_movements (tenant_id, movement_number, item_id, type, quantity, stock_before, stock_after)
            VALUES (1, 'MOV-001', ?, 'in', 100, 0, 100)
        ", [$itemId]);
        $this->db->statement("
            INSERT INTO inventory_movements (tenant_id, movement_number, item_id, type, quantity, stock_before, stock_after)
            VALUES (1, 'MOV-002', ?, 'out', 30, 100, 70)
        ", [$itemId]);

        $audit = $auditor->audit(1);
        $this->assertFalse($audit->isBalanced);
        $this->assertCount(1, $audit->discrepancies);
        $this->assertEquals(100.0, (float) $audit->discrepancies[0]->actualValue);
        $this->assertEquals(70.0, (float) $audit->discrepancies[0]->expectedValue);

        // Reconcile
        $res = $auditor->reconcile(1, false);
        $this->assertEquals(1, $res['resolved']);

        $itemAfter = $this->db->selectOne("SELECT current_stock FROM items WHERE id = ?", [$itemId]);
        $this->assertEquals(70, (float) $itemAfter['current_stock']);

        $this->assertTrue($auditor->audit(1)->isBalanced);
    }

    public function testTariffSplitAuditorDetectsOverAllocations(): void
    {
        $auditor = new TariffSplitAuditor($this->db);

        // 1. Invoice item subtotal 100,000, but allocations total 120,000
        $this->db->statement("
            INSERT INTO invoices (id, tenant_id, invoice_number, contact_name, issue_date, grand_total, subtotal)
            VALUES (10, 1, 'INV-10', 'Pasien B', '2026-10-29', 100000, 100000)
        ");
        $this->db->statement("
            INSERT INTO tariff_fee_allocations (tenant_id, invoice_id, item_id, component_name, amount, status)
            VALUES (1, 10, 1, 'Jasa Medis Dokter', 80000, 'accrued')
        ");
        $this->db->statement("
            INSERT INTO tariff_fee_allocations (tenant_id, invoice_id, item_id, component_name, amount, status)
            VALUES (1, 10, 1, 'Jasa Sarana Klinik', 40000, 'accrued')
        ");

        // 2. Split rule exceeding 100%
        $this->db->statement("
            INSERT INTO tariff_split_rules (tenant_id, name, recipient_type, split_type, split_value)
            VALUES (1, 'Rule Rusak', 'performer', 'percentage', 120.0)
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
            INSERT INTO document_seals (
                tenant_id, verification_token, document_type, document_number, title,
                issuer_name, signer_name, subject_name, payload_snapshot, canonical_hash,
                signature, status
            ) VALUES (
                1, 'tok_med_001', 'medical_certificate', 'MED-001', 'Surat Keterangan Sehat',
                'Klinik Utama', 'dr. Andi', 'Budi', ?, ?, ?, 'active'
            )
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
        $this->assertStringContainsString('dimanipulasi', $audit2->discrepancies[0]->description);
    }

    public function testTenantReferentialAuditorDetectsLeakageAndOrphans(): void
    {
        $auditor = new TenantReferentialAuditor($this->db);

        // 1. Parent invoice for tenant 1
        $this->db->statement("
            INSERT INTO invoices (id, tenant_id, invoice_number, contact_name, issue_date, grand_total)
            VALUES (10, 1, 'INV-10', 'Pasien Test', '2026-10-29', 50000)
        ");

        // 2. Cross-tenant leakage: child invoice_item has tenant_id = 2 pointing to invoice 10 (tenant 1)
        $this->db->statement("
            INSERT INTO invoice_items (id, tenant_id, invoice_id, item_name, subtotal)
            VALUES (101, 2, 10, 'Obat A', 50000)
        ");

        // 3. Orphan item_batch pointing to non-existent item #999 (simulating dirty legacy/imported data)
        $this->db->statement("PRAGMA foreign_keys = OFF;");
        $this->db->statement("
            INSERT INTO item_batches (id, tenant_id, item_id, batch_number, expiry_date, current_quantity)
            VALUES (201, 1, 999, 'BATCH-GHOST', '2027-12-31', 50)
        ");
        $this->db->statement("PRAGMA foreign_keys = ON;");

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

    public function testNewAuditorsInspectInvariants(): void
    {
        // 1. StateMonotonicityAuditor
        $stateAuditor = new StateMonotonicityAuditor($this->db);
        $this->assertTrue($stateAuditor->audit(1)->isBalanced);

        // Introduce state transition violation
        $this->db->statement("
            INSERT INTO queue_tickets (tenant_id, ticket_number, prefix, sequence_number, ticket_date, status)
            VALUES (1, 'A-001', 'A', 1, '2026-10-29', 'calling')
        ");
        $stateAudit = $stateAuditor->audit(1);
        $this->assertFalse($stateAudit->isBalanced);

        // 2. ClosedPeriodAuditor
        $closedAuditor = new ClosedPeriodAuditor($this->db);
        $this->assertTrue($closedAuditor->audit(1)->isBalanced);

        // Insert valid account first
        $this->db->statement("
            INSERT INTO financial_accounts (id, tenant_id, code, name, initial_balance, current_balance)
            VALUES (100, 1, 'ACC-100', 'Kas Operasional', 1000, 1000)
        ");

        // Introduce temporal violation
        $this->db->statement("
            INSERT INTO financial_transactions (tenant_id, transaction_number, description, transaction_date, type, source_account_id, amount, status)
            VALUES (1, 'TX-ANOMALY', 'Tanggal Masa Depan', '2150-01-01', 'inflow', 100, 1000, 'completed')
        ");
        $closedAudit = $closedAuditor->audit(1);
        $this->assertFalse($closedAudit->isBalanced);

        // 3. DomainBoundaryAuditor
        $domainAuditor = new DomainBoundaryAuditor($this->db);
        // Insert parent invoice first
        $this->db->statement("
            INSERT INTO invoices (id, tenant_id, invoice_number, contact_name, issue_date, grand_total, subtotal)
            VALUES (10, 1, 'INV-10', 'Pasien Test', '2026-10-29', 10000, 10000)
        ");
        // Introduce boundary violation
        $this->db->statement("
            INSERT INTO invoice_items (tenant_id, invoice_id, item_name, quantity, unit_price, subtotal)
            VALUES (1, 10, 'Item Rusak', -5, 10000, -50000)
        ");
        $domainAudit = $domainAuditor->audit(1);
        $this->assertFalse($domainAudit->isBalanced);
    }

    public function testReconciliationManagerFullAuditAndSyncLogging(): void
    {
        $manager = new ReconciliationManager($this->db);

        // Create initial clean audit (11 pillars registered)
        $report = $manager->audit();
        $this->assertCount(11, $report->pillars);

        // Introduce a drift in financial accounts
        $this->db->statement("
            INSERT INTO financial_accounts (id, tenant_id, code, name, initial_balance, current_balance)
            VALUES (5, 1, 'ACC-BANK', 'Bank Operasional', 2000000, 2000000)
        ");
        $this->db->statement("
            INSERT INTO financial_transactions (tenant_id, transaction_number, description, transaction_date, type, source_account_id, amount, status)
            VALUES (1, 'TX-005', 'Inflow Operasional', '2026-10-29', 'inflow', 5, 500000, 'completed')
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
