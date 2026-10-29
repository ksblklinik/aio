<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Reconciliation\Auditors\FitnessSafetyAuditor;
use Core\Reconciliation\Auditors\ProvenanceLineageAuditor;
use Core\Reconciliation\Auditors\RestrictedFundAuditor;
use Core\Reconciliation\ReconciliationManager;
use Core\Security\AppKey;
use Tests\TestCase;

class ExpandedUniversalInvariantsTest extends TestCase
{
    private string $tempDir;
    private string $tempDbPath;
    private Connection $db;
    private ReconciliationManager $reconciler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aio_expanded_inv_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->tempDbPath = $this->tempDir . DIRECTORY_SEPARATOR . 'test_expanded_inv.sqlite';
        $this->db = new Connection("sqlite:{$this->tempDbPath}");

        // Run official core migrations
        $migrator = new Migrator($this->db);
        $migrator->runMigrationsFrom(dirname(__DIR__, 2) . '/database/migrations');

        $this->reconciler = new ReconciliationManager($this->db);

        // Seed 2 distinct tenants: 1 Profit, 1 Non-Profit
        $this->db->statement("
            INSERT INTO tenants (id, name, slug) VALUES 
            (1, 'Klinik Bedah Spesialis (Profit)', 'klinik-bedah'),
            (2, 'Posko Tanggap Bencana NGO (Non-Profit)', 'posko-bencana')
        ");

        // Seed base items
        $this->db->statement("
            INSERT INTO items (id, tenant_id, code, name, type, current_stock, cost_price, selling_price, track_inventory, is_active) VALUES
            (1, 1, 'MED-SUTURE-01', 'Benang Operasi Prolene 3-0', 'goods', 45.0, 50000.0, 85000.0, 1, 1),
            (50, 2, 'MED-PARACETAMOL-01', 'Paracetamol 500mg Donasi WHO', 'goods', 980.0, 0.0, 0.0, 1, 1)
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

    public function testProvenanceLineageAuditorDetectsOrphanAndCrossTenantParents(): void
    {
        $auditor = new ProvenanceLineageAuditor($this->db);

        // Seed valid parent batch
        $this->db->statement("
            INSERT INTO item_batches (id, tenant_id, item_id, batch_number, expiry_date, initial_quantity, current_quantity, status)
            VALUES (101, 1, 1, 'BATCH-PARENT-A', '2028-12-31', 100, 50, 'active')
        ");

        // Seed valid child batch referencing parent
        $this->db->statement("
            INSERT INTO item_batches (id, tenant_id, item_id, batch_number, expiry_date, initial_quantity, current_quantity, status, notes)
            VALUES (102, 1, 1, 'BATCH-CHILD-A1', '2028-12-31', 25, 25, 'active', 'racikan parent_batch_id: 101')
        ");

        $cleanReport = $auditor->audit(1);
        $this->assertTrue($cleanReport->isBalanced);
        $this->assertSame(0, $cleanReport->discrepancyCount);

        // Seed invalid child batch referencing non-existent parent_batch_id 999
        $this->db->statement("
            INSERT INTO item_batches (id, tenant_id, item_id, batch_number, expiry_date, initial_quantity, current_quantity, status, notes)
            VALUES (103, 1, 1, 'BATCH-ORPHAN-C', '2028-12-31', 10, 10, 'active', 'compound parent_batch_id: 999')
        ");

        $dirtyReport = $auditor->audit(1);
        $this->assertFalse($dirtyReport->isBalanced);
        $this->assertSame(1, $dirtyReport->discrepancyCount);
        $this->assertSame('orphan provenance parent', $dirtyReport->discrepancies[0]->actualValue);

        // Seed cross-tenant batch referencing tenant 1's batch from tenant 2
        $this->db->statement("
            INSERT INTO item_batches (id, tenant_id, item_id, batch_number, expiry_date, initial_quantity, current_quantity, status, notes)
            VALUES (201, 2, 50, 'BATCH-CROSS-T2', '2028-12-31', 15, 15, 'active', 'stolen parent_batch_id: 101')
        ");

        $crossTenantReport = $auditor->audit(2);
        $this->assertFalse($crossTenantReport->isBalanced);
        $this->assertSame(1, $crossTenantReport->discrepancyCount);
        $this->assertSame('critical', $crossTenantReport->discrepancies[0]->severity);
        $this->assertStringContainsString('Kebocoran silsilah', $crossTenantReport->discrepancies[0]->description);
    }

    public function testRestrictedFundAuditorDetectsNegativeBalanceAndExcessiveDebits(): void
    {
        $auditor = new RestrictedFundAuditor($this->db);

        // Seed a valid restricted fund account with positive balance
        $this->db->statement("
            INSERT INTO financial_accounts (id, tenant_id, code, name, type, current_balance, is_active)
            VALUES (501, 2, 'RESTRICTED_EARTHQUAKE_2026', 'Dana Bantuan Gempa Cianjur (Terikat)', 'restricted_equity', 50000000.0, 1)
        ");

        $cleanReport = $auditor->audit(2);
        $this->assertTrue($cleanReport->isBalanced);
        $this->assertSame(0, $cleanReport->discrepancyCount);

        // Make the balance negative (illegal deficit on restricted fund)
        $this->db->statement("UPDATE financial_accounts SET current_balance = -5000000.0 WHERE id = 501");

        $deficitReport = $auditor->audit(2);
        $this->assertFalse($deficitReport->isBalanced);
        $this->assertSame(1, $deficitReport->discrepancyCount);
        $this->assertSame('critical', $deficitReport->discrepancies[0]->severity);
        $this->assertStringContainsString('mengalami defisit', $deficitReport->discrepancies[0]->description);
    }

    public function testFitnessSafetyAuditorDetectsAndAutoReconcilesExpiredBatches(): void
    {
        $auditor = new FitnessSafetyAuditor($this->db);

        // Seed an expired batch that is erroneously still marked 'active'
        $this->db->statement("
            INSERT INTO item_batches (id, tenant_id, item_id, batch_number, expiry_date, initial_quantity, current_quantity, status)
            VALUES (301, 1, 1, 'BATCH-EXPIRED-STILL-ACTIVE', '2020-01-01', 50, 20, 'active')
        ");

        $report = $auditor->audit(1);
        $this->assertFalse($report->isBalanced);
        $this->assertSame(1, $report->discrepancyCount);
        $this->assertTrue($report->discrepancies[0]->autoResolvable);

        // Execute auto-reconcile
        $reconcileResult = $auditor->reconcile(1);
        $this->assertSame(1, $reconcileResult['resolved']);

        // Verify status was changed to 'expired'
        $updated = $this->db->selectOne("SELECT status FROM item_batches WHERE id = 301");
        $this->assertSame('expired', $updated['status']);

        // Verify re-audit is now clean of active expired batches
        $postReconcileReport = $auditor->audit(1);
        $this->assertTrue($postReconcileReport->isBalanced);
    }

    public function testFitnessSafetyAuditorDetectsDispensingOfExpiredAndRecalledBatches(): void
    {
        $auditor = new FitnessSafetyAuditor($this->db);

        // Seed a recalled batch
        $this->db->statement("
            INSERT INTO item_batches (id, tenant_id, item_id, batch_number, expiry_date, initial_quantity, current_quantity, status)
            VALUES (401, 1, 1, 'BATCH-BPOM-RECALLED', '2028-01-01', 100, 80, 'recalled')
        ");

        // Seed an inventory movement dispensing from the recalled batch
        $this->db->statement("
            INSERT INTO inventory_movements (id, tenant_id, movement_number, item_id, batch_id, type, quantity, stock_before, stock_after, reference_type, reference_id, created_at)
            VALUES (1, 1, 'MOV-DISPENSE-001', 1, 401, 'out', 5, 50.0, 45.0, 'prescription', 'RX-001', '2026-10-29 10:00:00')
        ");

        $report = $auditor->audit(1);
        $this->assertFalse($report->isBalanced);
        $this->assertGreaterThanOrEqual(1, $report->discrepancyCount);
        $this->assertSame('critical', $report->discrepancies[0]->severity);
        $this->assertStringContainsString('Pelanggaran Karantina', $report->discrepancies[0]->description);
    }

    public function testFullReconciliationWithAll11PillarsForProfitEntity(): void
    {
        // 1. Chart of Accounts & Balanced Cash
        $this->db->statement("
            INSERT INTO financial_accounts (id, tenant_id, code, name, type, initial_balance, current_balance, is_active) VALUES
            (10, 1, '1001', 'Kas Kasir Bedah', 'cash', 0.0, 2500000.0, 1)
        ");

        $this->db->statement("
            INSERT INTO financial_transactions (tenant_id, transaction_number, type, source_account_id, amount, transaction_date, description, status) VALUES
            (1, 'TX-IN-001', 'inflow', 10, 2500000.0, '2026-10-29', 'Pembayaran operasi pasien umum', 'completed')
        ");

        // 2. Invoice & Cashier Receipt
        $this->db->statement("
            INSERT INTO invoices (id, tenant_id, invoice_number, contact_name, issue_date, subtotal, discount_total, tax_total, grand_total, amount_paid, amount_due, payment_status, status, created_at) VALUES
            (1, 1, 'INV/SURGERY/001', 'Pasien Wijaya', '2026-10-29', 2500000.0, 0.0, 0.0, 2500000.0, 2500000.0, 0.0, 'paid', 'issued', '2026-10-29 09:00:00')
        ");

        $this->db->statement("
            INSERT INTO invoice_payments (id, tenant_id, invoice_id, payment_number, amount, payment_method, account_id, payment_date) VALUES
            (1, 1, 1, 'PAY/001', 2500000.0, 'cash', 10, '2026-10-29')
        ");

        // 3. Batch with valid lineage, and movements
        $this->db->statement("
            INSERT INTO item_batches (id, tenant_id, item_id, batch_number, expiry_date, initial_quantity, current_quantity, status) VALUES
            (1, 1, 1, 'LOT-MFG-2026-A', '2029-01-01', 50.0, 45.0, 'active')
        ");

        $this->db->statement("
            INSERT INTO inventory_movements (id, tenant_id, movement_number, item_id, batch_id, type, quantity, stock_before, stock_after, reference_type, reference_id, created_at) VALUES
            (10, 1, 'MOV-IN-001', 1, 1, 'in', 50.0, 0.0, 50.0, 'purchase_order', 'PO-001', '2026-10-01 08:00:00'),
            (11, 1, 'MOV-OUT-001', 1, 1, 'out', 5.0, 50.0, 45.0, 'invoice', 'INV/SURGERY/001', '2026-10-29 09:00:00')
        ");

        // 4. Split fee rule (sum <= 100%)
        $this->db->statement("
            INSERT INTO tariff_price_books (id, tenant_id, name, code, is_default, is_active) VALUES
            (1, 1, 'Tarif Umum Bedah', 'TARIF-BEDAH', 1, 1)
        ");

        $this->db->statement("
            INSERT INTO tariff_split_rules (id, tenant_id, price_book_id, item_id, name, recipient_type, split_type, split_value) VALUES
            (1, 1, 1, 1, 'Jasa Dokter Bedah', 'performer', 'percentage', 70.0),
            (2, 1, 1, 1, 'Jasa Sarana Klinik', 'facility', 'percentage', 30.0)
        ");

        // Run full 11-pillar reconciliation for tenant 1 (Profit)
        $report = $this->reconciler->audit(1);

        $this->assertTrue($report->isHealthy, 'Seluruh 11 pilar universal wajib sehat pada entitas profit.');
        $this->assertSame(0, $report->discrepancyCount, 'Harus 0 discrepansi pada entitas profit.');
        $this->assertGreaterThanOrEqual(11, count($report->pillars));
    }

    public function testFullReconciliationWithAll11PillarsForNonProfitEntity(): void
    {
        $tId = 2;

        // 1. Restricted Fund Accounts & Donations
        $this->db->statement("
            INSERT INTO financial_accounts (id, tenant_id, code, name, type, initial_balance, current_balance, is_active) VALUES
            (101, 2, 'KAS_POSKO', 'Kas Operasional Posko Bencana', 'cash', 0.0, 45000000.0, 1),
            (201, 2, 'RESTRICTED_DONOR_GRANT', 'Dana Amanah Bencana Gempa (Terikat)', 'cash', 0.0, 45000000.0, 1)
        ");

        $this->db->statement("
            INSERT INTO financial_transactions (tenant_id, transaction_number, type, source_account_id, amount, transaction_date, description, status) VALUES
            (2, 'TX-GRANT-001', 'inflow', 101, 50000000.0, '2026-10-25', 'Penerimaan dana hibah kas operasional', 'completed'),
            (2, 'TX-GRANT-002', 'inflow', 201, 50000000.0, '2026-10-25', 'Penerimaan dana hibah kemanusiaan terikat', 'completed'),
            (2, 'TX-EXP-001', 'outflow', 101, 5000000.0, '2026-10-28', 'Distribusi logistik air bersih dan tenda darurat', 'completed'),
            (2, 'TX-EXP-002', 'outflow', 201, 5000000.0, '2026-10-28', 'Penyerapan dana hibah untuk air bersih & tenda', 'completed')
        ");

        // 2. Free Healthcare Invoice (Rp 0 Social Invariant)
        $this->db->statement("
            INSERT INTO invoices (id, tenant_id, invoice_number, contact_name, issue_date, subtotal, discount_total, tax_total, grand_total, amount_paid, amount_due, payment_status, status, created_at) VALUES
            (10, 2, 'INV/RELIEF/FREE-001', 'Warga Pengungsi', '2026-10-29', 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 'paid', 'issued', '2026-10-29 10:00:00')
        ");

        // 3. Donated medicine lot with valid donor provenance in notes
        $this->db->statement("
            INSERT INTO item_batches (id, tenant_id, item_id, batch_number, expiry_date, initial_quantity, current_quantity, status, notes) VALUES
            (500, 2, 50, 'BATCH-WHO-RELIEF-2026', '2028-06-30', 1000.0, 980.0, 'active', 'Donor: WHO Humanitarian Aid manifest-998')
        ");

        $this->db->statement("
            INSERT INTO inventory_movements (id, tenant_id, movement_number, item_id, batch_id, type, quantity, stock_before, stock_after, reference_type, reference_id, created_at) VALUES
            (100, 2, 'MOV-DON-001', 50, 500, 'in', 1000.0, 0.0, 1000.0, 'donation_manifest', 'WHO-GRANT-01', '2026-10-26 09:00:00'),
            (101, 2, 'MOV-DISP-001', 50, 500, 'out', 20.0, 1000.0, 980.0, 'invoice', 'INV/RELIEF/FREE-001', '2026-10-29 10:00:00')
        ");

        // 4. Cryptographic seal for humanitarian aid receipt
        $rawDoc = json_encode(['relief_action' => 'Free Surgery & Aid', 'beneficiary' => 'Warga Pengungsi']);
        $canonicalHash = hash('sha256', $rawDoc);
        $tenantKey = hash_hmac('sha256', (string) $tId, AppKey::get());
        $sig = hash_hmac('sha256', $canonicalHash, $tenantKey);

        $this->db->statement("
            INSERT INTO document_seals (
                id, tenant_id, verification_token, document_type, document_number, title,
                issuer_name, signer_name, subject_name, payload_snapshot, canonical_hash, signature, status, created_at
            ) VALUES (
                2, 2, 'TOKEN-RELIEF-AID-002', 'bast_bantuan', 'BAST/2026/001', 'BAST Penyaluran Bantuan Korban Bencana',
                'Posko Tanggap Bencana', 'Koordinator Logistik', 'Warga Pengungsi', '{$rawDoc}', '{$canonicalHash}', '{$sig}', 'active', '2026-10-29 11:00:00'
            )
        ");

        // Run full 11-pillar reconciliation for tenant 2 (Non-Profit)
        $report = $this->reconciler->audit(2);

        $this->assertTrue($report->isHealthy, 'Seluruh 11 pilar universal wajib sehat pada entitas non-profit.');
        $this->assertSame(0, $report->discrepancyCount, 'Harus 0 discrepansi pada entitas non-profit.');
        $this->assertGreaterThanOrEqual(11, count($report->pillars));
    }
}
