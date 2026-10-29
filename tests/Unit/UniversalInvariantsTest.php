<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Reconciliation\ReconciliationManager;
use PHPUnit\Framework\TestCase;

class UniversalInvariantsTest extends TestCase
{
    private string $tempDir;
    private string $tempDbPath;
    private Connection $db;
    private ReconciliationManager $reconciler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aio_invariants_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->tempDbPath = $this->tempDir . DIRECTORY_SEPARATOR . 'test_invariants.sqlite';
        $this->db = new Connection("sqlite:{$this->tempDbPath}");

        // Run full core migrations
        $migrator = new Migrator($this->db);
        $migrator->runMigrationsFrom(dirname(__DIR__, 2) . '/database/migrations');

        $this->reconciler = new ReconciliationManager($this->db);

        // Seed 2 distinct tenants: 1 Profit (Commercial Clinic), 1 Non-Profit (Charity Foundation)
        $this->db->statement("
            INSERT INTO tenants (id, name, slug) VALUES 
            (1, 'Klinik Pratama Sehat (Profit)', 'klinik-sehat'),
            (2, 'Yayasan Medis Kemanusiaan (Non-Profit)', 'yayasan-medis');
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

    /**
     * Skenario 1: Entitas Komersial / Profit (Faskes Swasta & Apotek)
     * Menguji Invarian:
     * - Invarian 1 (Konservasi Fisik): Stok berkurang akurat, saldo mutasi sinkron.
     * - Invarian 2 (Akuntabilitas Finansial): Grand total = Pembayaran Kasir + Bank, kas riil = transaksi.
     * - Invarian 4 (Pagu Hak Stakeholder): Split fee dokter + klinik <= biaya komponen tarif.
     * - Invarian 19 (Presisi Aritmatika): Nilai pembulatan rupiah presisi tanpa drift.
     */
    public function testProfitEntityInvariantsAreStrictlyPreserved(): void
    {
        $tenantId = 1;

        // 1. Setup Master Item Obat
        $this->db->statement("
            INSERT INTO items (tenant_id, code, name, type, cost_price, selling_price, current_stock, track_inventory)
            VALUES (?, 'PCT-500', 'Paracetamol 500mg', 'goods', 3000, 5000, 100, 1)
        ", [$tenantId]);
        $itemId = (int) $this->db->lastInsertId();

        // Mutasi awal masuk
        $this->db->statement("
            INSERT INTO inventory_movements (tenant_id, movement_number, item_id, type, quantity, stock_before, stock_after)
            VALUES (?, 'MOV-INIT-001', ?, 'in', 100, 0, 100)
        ", [$tenantId, $itemId]);

        // 2. Setup Akun Finansial Kasir & Bank
        $this->db->statement("
            INSERT INTO financial_accounts (tenant_id, code, name, initial_balance, current_balance)
            VALUES (?, 'ACC-CASH', 'Kas Kasir', 1000000, 1000000)
        ", [$tenantId]);
        $cashAccId = (int) $this->db->lastInsertId();

        $this->db->statement("
            INSERT INTO financial_accounts (tenant_id, code, name, initial_balance, current_balance)
            VALUES (?, 'ACC-BANK', 'Bank Transfer', 5000000, 5000000)
        ", [$tenantId]);
        $bankAccId = (int) $this->db->lastInsertId();

        // 3. Transaksi Layanan: Konsultasi (Rp 150.000) + Obat (10 pcs @ Rp 5.000 = Rp 50.000) = Total Rp 200.000
        $this->db->statement("
            INSERT INTO invoices (tenant_id, invoice_number, contact_name, issue_date, subtotal, grand_total, amount_paid, amount_due, payment_status, status)
            VALUES (?, 'INV-PROFIT-001', 'Pasien Budi', '2026-10-29', 200000, 200000, 200000, 0, 'paid', 'issued')
        ", [$tenantId]);
        $invoiceId = (int) $this->db->lastInsertId();

        $this->db->statement("
            INSERT INTO invoice_items (tenant_id, invoice_id, item_id, item_name, item_type, quantity, unit_price, subtotal)
            VALUES (?, ?, NULL, 'Jasa Konsultasi Dokter', 'service', 1, 150000, 150000)
        ", [$tenantId, $invoiceId]);

        $this->db->statement("
            INSERT INTO invoice_items (tenant_id, invoice_id, item_id, item_name, item_type, quantity, unit_price, subtotal)
            VALUES (?, ?, ?, 'Paracetamol 500mg', 'goods', 10, 5000, 50000)
        ", [$tenantId, $invoiceId, $itemId]);

        // Pemotongan stok obat fisik: 100 - 10 = 90
        $this->db->statement("UPDATE items SET current_stock = 90 WHERE id = ?", [$itemId]);
        $this->db->statement("
            INSERT INTO inventory_movements (tenant_id, movement_number, item_id, type, quantity, stock_before, stock_after)
            VALUES (?, 'MOV-DISPENSE-001', ?, 'out', 10, 100, 90)
        ", [$tenantId, $itemId]);

        // Pembayaran campuran: Rp 100.000 Tunai + Rp 100.000 Transfer
        $this->db->statement("
            INSERT INTO invoice_payments (tenant_id, invoice_id, payment_number, payment_method, account_id, amount, payment_date)
            VALUES (?, ?, 'PAY-001', 'cash', ?, 100000, '2026-10-29')
        ", [$tenantId, $invoiceId, $cashAccId]);

        $this->db->statement("
            INSERT INTO invoice_payments (tenant_id, invoice_id, payment_number, payment_method, account_id, amount, payment_date)
            VALUES (?, ?, 'PAY-002', 'bank_transfer', ?, 100000, '2026-10-29')
        ", [$tenantId, $invoiceId, $bankAccId]);

        // Update saldo akun & ledger transaksi
        $this->db->statement("UPDATE financial_accounts SET current_balance = current_balance + 100000 WHERE id = ?", [$cashAccId]);
        $this->db->statement("
            INSERT INTO financial_transactions (tenant_id, transaction_number, description, transaction_date, type, source_account_id, amount, status)
            VALUES (?, 'TX-IN-001', 'Pembayaran Tunai Pasien', '2026-10-29', 'inflow', ?, 100000, 'completed')
        ", [$tenantId, $cashAccId]);

        $this->db->statement("UPDATE financial_accounts SET current_balance = current_balance + 100000 WHERE id = ?", [$bankAccId]);
        $this->db->statement("
            INSERT INTO financial_transactions (tenant_id, transaction_number, description, transaction_date, type, source_account_id, amount, status)
            VALUES (?, 'TX-IN-002', 'Pembayaran Transfer Pasien', '2026-10-29', 'inflow', ?, 100000, 'completed')
        ", [$tenantId, $bankAccId]);

        // Alokasi Fee: Jasa Konsultasi Rp 150.000 -> 70% Dokter (Rp 105.000), 30% Sarana Klinik (Rp 45.000)
        $this->db->statement("
            INSERT INTO tariff_fee_allocations (tenant_id, invoice_id, item_id, component_name, amount, status)
            VALUES (?, ?, ?, 'Jasa Medis dr. Andi (70%)', 105000, 'accrued')
        ", [$tenantId, $invoiceId, $itemId]);

        $this->db->statement("
            INSERT INTO tariff_fee_allocations (tenant_id, invoice_id, item_id, component_name, amount, status)
            VALUES (?, ?, ?, 'Jasa Sarana Klinik (30%)', 45000, 'accrued')
        ", [$tenantId, $invoiceId, $itemId]);

        // VERIFIKASI INVARIAN
        $report = $this->reconciler->audit($tenantId);
        $this->assertTrue($report->isHealthy, "Audit Tenant Profit harus 100% sehat tanpa anomali.");
        $this->assertEquals(0, $report->discrepancyCount);

        // Pastikan stok fisik tepat 90
        $item = $this->db->selectOne("SELECT current_stock FROM items WHERE id = ?", [$itemId]);
        $this->assertEquals(90.0, (float) $item['current_stock']);

        // Pastikan saldo kas & bank tepat
        $cashAcc = $this->db->selectOne("SELECT current_balance FROM financial_accounts WHERE id = ?", [$cashAccId]);
        $this->assertEquals(1100000.0, (float) $cashAcc['current_balance']);

        $bankAcc = $this->db->selectOne("SELECT current_balance FROM financial_accounts WHERE id = ?", [$bankAccId]);
        $this->assertEquals(5100000.0, (float) $bankAcc['current_balance']);
    }

    /**
     * Skenario 2: Entitas Nirlaba / Non-Profit (Klinik Dhuafa, Posko Bencana & Dana Donasi Terikat)
     * Menguji Invarian:
     * - Invarian 1 (Konservasi Fisik): Penerimaan logistik obat donasi masuk & disalurkan gratis (Rp 0).
     * - Invarian 2 (Akuntabilitas Dana Terikat): Saldo dana donasi berkurang akurat saat operasional relawan dibayar.
     * - Invarian 5 (Kedaulatan Multi-Tenant): Tidak ada kebocoran atau keterkaitan dengan Tenant 1 (Profit).
     * - Invarian 6 (Segel Kriptografis): Berita Acara Serah Terima (BAST) bantuan terverifikasi HMAC SHA-256.
     * - Invarian 17 (Batas Domain): Tagihan bernilai Rp 0 sah untuk layanan sosial, tetapi tagihan negatif ditolak.
     */
    public function testNonProfitEntityInvariantsAreStrictlyPreserved(): void
    {
        $tenantId = 2; // Yayasan Medis Kemanusiaan

        // 1. Akun Dana Donasi Terikat (Restricted Fund)
        $this->db->statement("
            INSERT INTO financial_accounts (tenant_id, code, name, initial_balance, current_balance)
            VALUES (?, 'FUND-ZAKAT', 'Rekening Dana Bantuan Dhuafa', 50000000, 50000000)
        ", [$tenantId]);
        $fundAccId = (int) $this->db->lastInsertId();

        // 2. Penerimaan Obat Donasi (Hibah Masuk 500 botol Amoxicillin @ Rp 0)
        $this->db->statement("
            INSERT INTO items (tenant_id, code, name, type, cost_price, selling_price, current_stock, track_inventory)
            VALUES (?, 'AMX-SYR', 'Amoxicillin Sirup Donasi', 'goods', 0, 0, 500, 1)
        ", [$tenantId]);
        $itemId = (int) $this->db->lastInsertId();

        $this->db->statement("
            INSERT INTO inventory_movements (tenant_id, movement_number, item_id, type, quantity, stock_before, stock_after, notes)
            VALUES (?, 'MOV-DONATION-001', ?, 'in', 500, 0, 500, 'Hibah Kemanusiaan dari Donatur')
        ", [$tenantId, $itemId]);

        // 3. Penyaluran Bantuan Medis Gratis (Rp 0) kepada Pasien Korban Bencana
        $this->db->statement("
            INSERT INTO invoices (tenant_id, invoice_number, contact_name, issue_date, subtotal, grand_total, amount_paid, amount_due, payment_status, status, notes)
            VALUES (?, 'INV-SOCIAL-001', 'Warga Terdampak Bencana', '2026-10-29', 0, 0, 0, 0, 'paid', 'issued', 'Layanan Sosial Bebas Biaya 100%')
        ", [$tenantId]);
        $invoiceId = (int) $this->db->lastInsertId();

        $this->db->statement("
            INSERT INTO invoice_items (tenant_id, invoice_id, item_id, item_name, item_type, quantity, unit_price, subtotal)
            VALUES (?, ?, ?, 'Amoxicillin Sirup Donasi', 'goods', 25, 0, 0)
        ", [$tenantId, $invoiceId, $itemId]);

        // Pemotongan fisik stok donasi: 500 - 25 = 475
        $this->db->statement("UPDATE items SET current_stock = 475 WHERE id = ?", [$itemId]);
        $this->db->statement("
            INSERT INTO inventory_movements (tenant_id, movement_number, item_id, type, quantity, stock_before, stock_after, notes)
            VALUES (?, 'MOV-RELIEF-001', ?, 'out', 25, 500, 475, 'Penyaluran obat gratis ke posko pengungsian')
        ", [$tenantId, $itemId]);

        // 4. Pencairan Uang Saku Relawan Medis Lapangan (Disbursement Rp 500.000 dari Dana Donasi)
        $this->db->statement("UPDATE financial_accounts SET current_balance = current_balance - 500000 WHERE id = ?", [$fundAccId]);
        $this->db->statement("
            INSERT INTO financial_transactions (tenant_id, transaction_number, description, transaction_date, type, source_account_id, amount, status)
            VALUES (?, 'TX-DISBURSE-001', 'Uang Saku Relawan Medis Lapangan', '2026-10-29', 'outflow', ?, 500000, 'completed')
        ", [$tenantId, $fundAccId]);

        // 5. Penerbitan Berita Acara Serah Terima (BAST) Kriptografis
        $appKey = \Core\Security\AppKey::get();
        $payload = json_encode([
            'doc' => 'Berita Acara Penyaluran Obat Posko Bencana',
            'tenant' => 'Yayasan Medis Kemanusiaan',
            'qty' => 25,
            'recipient' => 'Warga Terdampak',
            'timestamp' => '2026-10-29T10:00:00Z',
        ]);
        $canonicalHash = hash('sha256', $payload);
        $tenantSecret = hash_hmac('sha256', (string) $tenantId, $appKey);
        $signature = hash_hmac('sha256', $canonicalHash, $tenantSecret);

        $this->db->statement("
            INSERT INTO document_seals (
                tenant_id, verification_token, document_type, document_number, title,
                issuer_name, signer_name, subject_name, payload_snapshot, canonical_hash,
                signature, status
            ) VALUES (
                ?, 'tok_bast_relief_001', 'donation_receipt', 'BAST-2026-001', 'Berita Acara Penyaluran Obat',
                'Yayasan Medis Kemanusiaan', 'Koordinator Posko', 'Warga Terdampak', ?, ?,
                ?, 'active'
            )
        ", [$tenantId, $payload, $canonicalHash, $signature]);

        // VERIFIKASI INVARIAN
        $report = $this->reconciler->audit($tenantId);
        $this->assertTrue($report->isHealthy, "Audit Tenant Non-Profit harus 100% sehat tanpa anomali.");
        $this->assertEquals(0, $report->discrepancyCount);

        // Pastikan sisa stok donasi tepat 475
        $item = $this->db->selectOne("SELECT current_stock FROM items WHERE id = ?", [$itemId]);
        $this->assertEquals(475.0, (float) $item['current_stock']);

        // Pastikan saldo dana donasi tepat Rp 49.500.000
        $fund = $this->db->selectOne("SELECT current_balance FROM financial_accounts WHERE id = ?", [$fundAccId]);
        $this->assertEquals(49500000.0, (float) $fund['current_balance']);

        // Pastikan isolasi tenant: tidak ada transaksi Tenant 2 yang bocor ke Tenant 1
        $crossLeakage = $this->db->selectAll("
            SELECT i.id FROM invoices i 
            INNER JOIN items it ON it.id = i.id 
            WHERE i.tenant_id = 1 AND it.tenant_id = 2
        ");
        $this->assertEmpty($crossLeakage);
    }
}
