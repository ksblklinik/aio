# SPEC-028: Universal Finance & Cash Flow Engine

- **Status**: `In Progress`
- **Owner / Author**: Antigravity Platform Team
- **Tanggal Dibuat**: 2026-10-27
- **Target Modul**: `app/Modules/Finance/`
- **Tingkat Arsitektur**: `Level 2 (Shared Business Foundation)`

---

## 1. Problem Statement & Context
100% entitas organisasi di dunia—baik perusahaan rintisan, toko retail, klinik kesehatan, kantor konsultan jasa, sekolah, yayasan sosial/keagamaan, hingga UMKM—memerlukan pencatatan mutasi keuangan:
1. **Fragmentasi Pengelolaan Kas**: Banyak aplikasi mencatat pembayaran penjualan di modul kasir/invoice, namun mencatat pengeluaran operasional (listrik, gaji, bensin, ATK) di buku fisik atau spreadsheet terpisah tanpa rekonsiliasi saldo kas/bank yang nyata.
2. **Ketiadaan Bukti Fisik Transaksi**: Pengeluaran kas kecil sering tidak disertai foto kuitansi/struk nota, memicu celah kebocoran dana dan dispute saat audit.
3. **Pengeluaran Tanpa Kontrol Approval**: Staf bebas mencairkan uang kas tanpa validasi hierarki persetujuan manajerial (*disbursement approval*).
4. **Ketiadaan Laporan Finansial Instan**: Pemilik usaha kesulitan mengetahui secara cepat berapa uang tunai riil yang tersisa, berapa arus kas bersih (*cash flow*), dan apakah operasional bulan berjalan mengalami surplus (untung) atau defisit (rugi).

Solusinya adalah membangun **Modul Level 2: `Finance` (Universal Finance & Cash Flow Engine)** yang agnostik, otomatis terintegrasi dengan `Core\Ledger` (Double-Entry Bookkeeping), terhubung ke modul `Contact`, dan menyediakan kontrak shared service untuk dikonsumsi modul operasional lain di masa depan.

---

## 2. Goals & Non-Goals

### Goals
- **Manajemen Akun Kas & Bank (`financial_accounts`)**:
  - Mengelola multi-wadah kas nyata: Kas Operasional, Kasir Toko, Kas Kecil (Petty Cash), Rekening Giro/Tabungan Bank (BCA, Mandiri, BRI), dan Dompet Digital.
  - Enkripsi nomor rekening bank menggunakan `AES-256-GCM` via helper `encrypt()`.
- **Bagan Akun Universal / Chart of Accounts (`financial_categories`)**:
  - Kategori pendapatan dan beban operasional berstandar akuntansi dengan seeder template bawaan otomatis.
- **Pencatatan Transaksi Arus Kas (`financial_transactions`)**:
  - Mendukung transaksi: `inflow` (Uang Masuk), `outflow` (Uang Keluar), dan `transfer` (Pindah Dana Antar-Kas).
  - Penomoran transaksi otomatis berurutan per tahun via `sequence('finance', 'TRX', 5, 'yearly')` (e.g. `TRX/2026/00001`).
  - Terkoneksi opsional ke entitas kontak (`contact_id` dari modul `Contact`).
- **Otomatisasi Pembukuan Jurnal Berpasangan (Double-Entry Ledger Integration)**:
  - Setiap mutasi yang selesai otomatis membentuk jurnal debit-kredit seimbang melalui `Core\Ledger\LedgerManager` (SPEC-018).
- **Lampiran Bukti Struk/Nota Fisik (`receipt_file_path`)**:
  - Mendukung unggah foto struk/nota atau dokumen PDF kuitansi per transaksi melalui layanan file storage platform.
- **Alur Persetujuan Pengeluaran Kas (Disbursement Approval Workflow)**:
  - Pengeluaran kas yang melampaui ambang batas memerlukan otorisasi (*approval*) sebelum saldo kas terpotong.
- **3 Laporan Finansial Instan**:
  - Laporan Arus Kas (*Cash Flow Statement*).
  - Laporan Laba Rugi Sederhana (*Profit & Loss Statement*).
  - Buku Kas & Rekening Koran (*Account Ledger Statement*).
- **Exported Shared Contract Interface (`FinanceRegistryInterface`)**:
  - Mengekspos helper global `finance(): FinanceRegistryInterface` untuk pencatatan pembayaran instan dari modul bisnis lain.

### Non-Goals
- Tidak mencakup modul perpajakan korporat rumit (e-Faktur PPN/PPh 23) atau konsolidasi holding multi-entitas internasional.

---

## 3. Database Schema Design

```
┌─────────────────────────────────────────────────────────────────┐
│                      financial_accounts                         │
├─────────────────────────────────────────────────────────────────┤
│ id (PK, int)                                                    │
│ tenant_id (FK, int)                                             │
│ code (varchar) e.g. KAS-01, BCA-01                              │
│ name (varchar) e.g. Kas Operasional, Rekening BCA Utama         │
│ type ('cash' | 'bank' | 'ewallet')                              │
│ account_number (text, encrypted AES-256-GCM)                    │
│ bank_name (varchar, nullable)                                   │
│ currency (varchar, default 'IDR')                               │
│ initial_balance (decimal/float)                                 │
│ current_balance (decimal/float)                                 │
│ is_active (bool, default true)                                  │
│ created_at, updated_at                                          │
└────────────────┬────────────────────────────────────────────────┘
                 │ 1:N
                 ▼
┌─────────────────────────────────────────────────────────────────┐
│                    financial_transactions                       │
├─────────────────────────────────────────────────────────────────┤
│ id (PK, int)                                                    │
│ tenant_id (FK, int)                                             │
│ transaction_number (varchar, unique per tenant) e.g. TRX/...   │
│ type ('inflow' | 'outflow' | 'transfer')                        │
│ source_account_id (FK financial_accounts)                       │
│ target_account_id (FK financial_accounts, nullable - transfer)  │
│ category_id (FK financial_categories, nullable - transfer)     │
│ contact_id (FK contacts, nullable)                              │
│ amount (decimal/float)                                          │
│ transaction_date (date)                                         │
│ status ('draft' | 'pending_approval' | 'completed' | 'cancelled')│
│ reference_number (varchar, nullable - no faktur/kuitansi luar)  │
│ description (text)                                              │
│ receipt_file_path (varchar, nullable)                           │
│ approved_by (FK users, nullable)                                │
│ approved_at (timestamp, nullable)                               │
│ created_by (FK users, nullable)                                 │
│ created_at, updated_at                                          │
└─────────────────────────────────────────────────────────────────┘
                 ▲
                 │ N:1
┌────────────────┴────────────────────────────────────────────────┐
│                     financial_categories                        │
├─────────────────────────────────────────────────────────────────┤
│ id (PK, int)                                                    │
│ tenant_id (FK, int)                                             │
│ code (varchar) e.g. 4001, 5001                                  │
│ name (varchar) e.g. Pendapatan Jasa, Beban Listrik & Internet   │
│ type ('income' | 'expense' | 'asset' | 'liability' | 'equity')  │
│ parent_id (FK financial_categories, nullable)                  │
│ is_active (bool, default true)                                  │
│ created_at, updated_at                                          │
└─────────────────────────────────────────────────────────────────┘
```

---

## 4. Technical Architecture & Shared Contract

### Service Interface: `FinanceRegistryInterface`
```php
namespace App\Modules\Finance\Contracts;

interface FinanceRegistryInterface
{
    // Transaksi Kas
    public function recordInflow(int $accountId, float $amount, int $categoryId, ?int $contactId = null, string $description = '', ?string $referenceNumber = null, ?string $receiptPath = null): int;
    public function recordOutflow(int $accountId, float $amount, int $categoryId, ?int $contactId = null, string $description = '', ?string $referenceNumber = null, ?string $receiptPath = null, bool $requiresApproval = false): int;
    public function transfer(int $sourceAccountId, int $targetAccountId, float $amount, string $description = ''): int;

    // Approval Alur Pengeluaran
    public function approveOutflow(int $transactionId, int $userId): void;
    public function rejectOutflow(int $transactionId, int $userId, string $reason = ''): void;

    // Rekening & Saldo
    public function getBalance(int $accountId): float;
    public function getAccount(int $accountId): ?array;
    public function listAccounts(): array;

    // Laporan Keuangan
    public function getCashFlowStatement(string $startDate, string $endDate): array;
    public function getProfitLossStatement(string $startDate, string $endDate): array;
    public function getAccountLedgerStatement(int $accountId, string $startDate, string $endDate): array;
}
```

---

## 5. Test Strategy
- **Unit Testing**:
  - `tests/Unit/FinanceModuleTest.php`:
    - Menguji pembuatan akun kas, bank, dan enkripsi nomor rekening.
    - Menguji pencatatan penerimaan uang (*Inflow*) dan pemotongan/penambahan saldo secara atomik.
    - Menguji pencatatan pengeluaran uang (*Outflow*) dan pembentukan jurnal debit-kredit ganda di `Core\Ledger`.
    - Menguji transfer saldo antar-rekening kas/bank.
    - Menguji alur *Disbursement Approval*: transaksi di atas limit berstatus `pending_approval` dan saldo baru terpotong saat di-approve.
    - Menguji keterhubungan dengan modul `Contact` (`contact_id`).
    - Menguji kalkulasi laporan Arus Kas (*Cash Flow*) dan Laba-Rugi (*P&L*).
    - Menguji isolasi data multi-tenant (Tenant 1 tidak melihat data kas Tenant 2).
- **Integration Verification**:
  - Eksekusi `./scripts/verify.ps1` (verifikasi seluruh suite, `tsc --noEmit`, dan pre-commit audit).

---

## 6. Atomic Task Checklist

- [ ] **Phase 1: Module Scaffolding & Database Migration**
  - [ ] 1.1 Buat manifest modul `app/Modules/Finance/module.json` kategori `resources`.
  - [ ] 1.2 Buat file migrasi SQL: `2026_10_27_000022_create_finance_tables.sql` (`financial_accounts`, `financial_categories`, `financial_transactions`).
  - [ ] 1.3 Jalankan migrasi via `php aio migrate` dan siapkan default COA seeder.
- [ ] **Phase 2: Domain Models & Contract Definition**
  - [ ] 2.1 Buat interface kontrak `App\Modules\Finance\Contracts\FinanceRegistryInterface`.
  - [ ] 2.2 Buat domain model: `FinancialAccount`, `FinancialCategory`, `FinancialTransaction`.
- [ ] **Phase 3: Service Layer & Double-Entry Ledger Integration**
  - [ ] 3.1 Implementasikan `App\Modules\Finance\Services\FinanceService` terintegrasi dengan `Core\Ledger\LedgerManager`.
  - [ ] 3.2 Implementasikan approval alur pengeluaran dan generator 3 laporan keuangan instan.
  - [ ] 3.3 Daftarkan service provider `FinanceServiceProvider` dan global helper `finance()`.
- [ ] **Phase 4: Web UI & REST API Controllers**
  - [ ] 4.1 Buat `FinanceController` untuk antarmuka kas/bank, mutasi transaksi, dan laporan ringkas.
  - [ ] 4.2 Daftarkan routes modul di `app/Modules/Finance/routes.php`.
- [ ] **Phase 5: Unit Testing & Self-Verification**
  - [ ] 5.1 Buat unit test komprehensif di `tests/Unit/FinanceModuleTest.php`.
  - [ ] 5.2 Jalankan `./scripts/verify.ps1`, pastikan seluruh test lolos 100%.
  - [ ] 5.3 Lakukan atomic commit dan merge branch fitur ke `main`.
  - [ ] 5.4 Perbarui dokumentasi spec dan `SESSION_HANDOFF.md`.
