# SPEC-039: Universal Cross-Module Integrity Auditor & Automated Data Reconciler

- **Status**: `Completed`
- **Owner / Author**: Antigravity & Lead Architect
- **Tanggal Dibuat**: 2026-10-28
- **Target File / Modul**: `core/Reconciliation`, `database/migrations`, `core/Console/Commands`, `core/Foundation/Application.php`, `core/helpers.php`

---

## 1. Problem Statement & Context
Dalam arsitektur SaaS modular universal, transaksi bisnis melibatkan banyak subsistem yang saling terkait: Kasir menerbitkan Faktur (`billing`), kasir menerima uang yang dicatat di Buku Besar (`finance`), obat dikeluarkan dari gudang multi-depo via FEFO (`inventory`), komisi jasa medis dipecah ke dokter (`tariff`), dan dokumen disahkan dengan segel kriptografis (`seal`).

Meskipun setiap modul memiliki validasi lokal dan database transactions, inkonsistensi data lintas modul (*cross-module data drift / silent corruption*) tetap dapat terjadi akibat:
1. **Kegagalan Parsial atau Uncaught Network/Worker Crash**:
   Faktur berhasil terbit dengan status `paid`, tetapi proses pencatatan jurnal kas terinterupsi, sehingga omzet di kasir berbeda dengan saldo riil bank.
2. **Drift Stok Fisik vs Ledger Pergerakan**:
   Kolom denormalisasi `items.current_stock` mengalami desinkronisasi terhadap akumulasi pergerakan riil di tabel `inventory_movements` atau terhadap saldo per lokasi gudang `inventory_stock_locations`.
3. **Over-Allocation Split-Fee Tarif**:
   Total bagi hasil klaim tagihan di `tariff_invoice_splits` melebihi nominal item tagihan yang dibayarkan pasien.
4. **Manipulasi Data / SQL Injection Siluman**:
   Administrator database lokal atau skrip nakal mengubah rekaman finansial atau status dokumen langsung di database. Rekonsiliasi berbasis verifikasi segel HMAC (`seal`) dapat mendeteksi manipulasi ini seketika.
5. **Cross-Tenant Leakage & Orphan Records**:
   Data anak (`invoice_items`, `stock_locations`, `batches`) yang tertinggal tanpa induk atau memiliki `tenant_id` yang berbeda dari induknya.

**Solusi**: Membangun **Universal Cross-Module Integrity Auditor & Automated Data Reconciler** (`Core\Reconciliation`) murni berbasis PHP yang mengaudit keseimbangan matematis 5 pilar universal, menghasilkan audit report komprehensif, dan mampu melakukan auto-sinkronisasi mandiri (*healing reconciliation*) tanpa merusak riwayat transaksi yang immutable.

---

## 2. Goals & Non-Goals

### Goals
- [x] Menyediakan tabel basis data `reconciliation_audit_logs` untuk mencatat riwayat temuan anomali dan jejak penyelesaiannya.
- [x] Menyediakan kontrak `Core\Reconciliation\ReconciliationManagerInterface` dan implementasi `Core\Reconciliation\ReconciliationManager`.
- [x] Mengimplementasikan **5 Pilar Audit Matematis Universal**:
  - **Pilar 1 (Finance vs Billing)**: Memvalidasi total nominal faktur `paid` sama dengan transaksi kas yang terhubung, dan tidak ada pembayaran siluman tanpa faktur.
  - **Pilar 2 (Inventory Stock vs Movements & Batches)**: Memvalidasi bahwa `items.current_stock` sama persis dengan `SUM(movements IN) - SUM(movements OUT)` serta seimbang dengan akumulasi lokasi depo dan batch FEFO.
  - **Pilar 3 (Billing vs Tariff Split-Fees)**: Memvalidasi bahwa total bagi hasil dokter/klinik tidak melebihi nilai item faktur terkait dan kuota persentase split tidak pernah lebih dari 100%.
  - **Pilar 4 (Cryptographic Seal HMAC Verification)**: Mengaudit seluruh dokumen berstatus sealed; menghitung ulang signature HMAC-SHA256 untuk mendeteksi data yang dimanipulasi secara ilegal di database.
  - **Pilar 5 (Multi-Tenant & Relational Integrity)**: Memvalidasi tidak ada record yatim (*orphan*) dan tidak ada kebocoran tenant silang antara induk dan anak.
- [x] Menyediakan engine perbaikan otomatis (*Safe Auto-Reconciler*):
  - Sinkronisasi ulang `current_stock` barang berdasarkan akumulasi ledger mutasi riil.
  - Sinkronisasi ulang saldo akun finansial (`finance_accounts.current_balance`) berdasarkan ledger jurnal transaksi.
  - Pencatatan log diskrepansi ke tabel `reconciliation_audit_logs`.
- [x] Menyediakan perintah CLI konsol:
  - `php aio reconcile:audit [--pillar=...] [--tenant=...]`: Audit menyeluruh dan menampilkan visualisasi tabel status kesehatan 5 pilar.
  - `php aio reconcile:sync [--dry-run] [--pillar=...] [--tenant=...]`: Menjalankan sinkronisasi mandiri perbaikan data.
- [x] Menyediakan helper global `reconcile(): ReconciliationManagerInterface`.

### Non-Goals
- Fitur ini TIDAK menghapus mutasi transaksi riil (`inventory_movements` atau `finance_transactions`), melainkan hanya memperbaiki nilai saldo akumulasi denormalisasi agar kembali sinkron dengan ledger kebenaran utama (*Single Source of Truth*).

---

## 3. Technical Architecture & Design

### Alur Kerja 5 Pilar Rekonsiliasi
```
┌────────────────────────────────────────────────────────┐
│           ReconciliationManager::audit()               │
└──────────────────────────┬─────────────────────────────┘
                           │
    ┌──────────────────────┼──────────────────────┐
    ▼                      ▼                      ▼
[Pilar 1: Finance]   [Pilar 2: Inventory]   [Pilar 3: Tariff]
Billing vs Kas       Stock vs Movements     Splits vs Invoices
    │                      │                      │
    └──────────────────────┼──────────────────────┘
                           ▼
    ┌─────────────────────────────────────────────┐
    │  [Pilar 4: Seal Anti-Tamper] (HMAC SHA-256) │
    │  [Pilar 5: Multi-Tenant & Referential]      │
    └──────────────────────┬──────────────────────┘
                           │
                           ▼
               [IntegrityAuditReport]
                           │
                           ▼
            ReconciliationManager::reconcile()
          (Atomic Sync & Discrepancy Logging)
```

### Data Model: `reconciliation_audit_logs`
```sql
CREATE TABLE IF NOT EXISTS reconciliation_audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    pillar TEXT NOT NULL,
    severity TEXT NOT NULL, -- 'info', 'warning', 'critical'
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

CREATE INDEX IF NOT EXISTS idx_reconciliation_tenant ON reconciliation_audit_logs(tenant_id, pillar, resolved);
```

### Interface Definition
```php
namespace Core\Reconciliation;

interface ReconciliationManagerInterface
{
    public function audit(?int $tenantId = null, ?string $pillar = null): IntegrityAuditReport;
    public function reconcile(?int $tenantId = null, ?string $pillar = null, bool $dryRun = false): ReconciliationResult;
    public function getPendingDiscrepancies(?int $tenantId = null): array;
    public function resolveDiscrepancy(int $logId, string $notes): bool;
}
```

---

## 4. Edge Cases & Error Scenarios
- **Perubahan Data Sedang Terjadi Saat Rekonsiliasi**: Auto-reconcile dibungkus dalam `lock()->run('reconciliation:sync:...')` untuk mencegah konflik saat kasir sedang memproses checkout.
- **Nilai Stok Negatif**: Jika hasil rekonsiliasi mutasi menghasilkan nilai minus karena transaksi masa lalu yang salah urutan, sistem mencatat diskrepansi berstatus `critical` dan menandai rekaman untuk investigasi operator.
- **Segel Kriptografis Rusak Akibat Kunci Berubah**: Jika `APP_KEY` diganti tanpa re-sealing, seluruh segel akan terdeteksi *tampered*. Sistem memberikan pesan diagnostik yang membedakan kegagalan akibat kunci vs manipulasi data.

---

## 5. Test Strategy
- **Unit Tests**:
  - `FinanceBillingReconciliationTest`: Uji pencocokan faktur paid dengan jurnal kas dan deteksi selisih nominal.
  - `InventoryStockLedgerReconciliationTest`: Uji deteksi phantom stock dan auto-sync stok dari ledger mutasi.
  - `TariffSplitFeeReconciliationTest`: Uji over-allocation split fee.
  - `CryptographicSealTamperTest`: Uji deteksi perubahan data manual pada record ber-segel.
  - `MultiTenantLeakageAuditTest`: Uji deteksi record yatim dan isolasi tenant.
  - `ReconciliationAutoSyncTest`: Uji auto-sync dalam mode dry-run dan execution run.
- **CLI Commands Tests**:
  - `php aio reconcile:audit`
  - `php aio reconcile:sync`

---

## 6. Atomic Task Checklist

- [x] **Phase 1: Database Migration & Core Contracts**
  - [x] 1.1 Buat migrasi `database/migrations/2026_10_28_000033_create_reconciliation_audit_logs_table.sql`.
  - [x] 1.2 Jalankan migrasi via `php aio migrate`.
  - [x] 1.3 Buat interface `Core\Reconciliation\ReconciliationManagerInterface`.
  - [x] 1.4 Buat DTO `Core\Reconciliation\IntegrityAuditReport`, `PillarAuditResult`, dan `ReconciliationResult`.

- [x] **Phase 2: 5-Pillar Auditors Implementation (TDD)**
  - [x] 2.1 Buat auditor `Core\Reconciliation\Auditors\FinanceBillingAuditor`.
  - [x] 2.2 Buat auditor `Core\Reconciliation\Auditors\InventoryStockAuditor`.
  - [x] 2.3 Buat auditor `Core\Reconciliation\Auditors\TariffSplitAuditor`.
  - [x] 2.4 Buat auditor `Core\Reconciliation\Auditors\CryptographicSealAuditor`.
  - [x] 2.5 Buat auditor `Core\Reconciliation\Auditors\TenantReferentialAuditor`.

- [x] **Phase 3: Reconciliation Engine & Auto-Sync**
  - [x] 3.1 Buat implementasi `Core\Reconciliation\ReconciliationManager`.
  - [x] 3.2 Daftarkan binding di `Core\Foundation\Application.php`.
  - [x] 3.3 Tambahkan helper global `reconcile()` di `core/helpers.php`.

- [x] **Phase 4: CLI Console Tooling**
  - [x] 4.1 Buat perintah `php aio reconcile:audit` (`core/Console/Commands/ReconcileAuditCommand.php`).
  - [x] 4.2 Buat perintah `php aio reconcile:sync` (`core/Console/Commands/ReconcileSyncCommand.php`).
  - [x] 4.3 Daftarkan perintah CLI di `Application::createCliKernel()`.

- [x] **Phase 5: Unit Testing & Self-Verification**
  - [x] 5.1 Tulis unit test komprehensif `tests/Unit/ReconciliationEngineTest.php`.
  - [x] 5.2 Jalankan PHPUnit test suite global dan pastikan 100% lolos.
  - [x] 5.3 Jalankan TypeScript typecheck (`npm run typecheck`) dan Vite build (`npm run build`).
  - [x] 5.4 Perbarui status dokumen spec ini menjadi `Completed`.
  - [x] 5.5 Perbarui `specs/README.md` dan `.agents/SESSION_HANDOFF.md`.
  - [x] 5.6 Buat atomic Git commit dan merge branch ke `main`.
