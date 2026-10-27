# SPEC-030: Universal Invoicing, Billing & Document Engine

- **Status**: `Approved`
- **Owner / Author**: Antigravity Platform Team
- **Tanggal Dibuat**: 2026-10-28
- **Target Modul**: `app/Modules/Billing/`
- **Tingkat Arsitektur**: `Level 2 (Shared Business Foundation)`

---

## 1. Problem Statement & Context
Tiga modul inti Level 2 terdahulu telah kokoh terbangun:
1. **Contact** (SPEC-027): Menyediakan identitas pelanggan, pasien, vendor, pemasok, dan organisasi.
2. **Finance** (SPEC-028): Mengelola rekening kas/bank, mutasi arus kas (*cash flow*), dan laporan laba-rugi (*P&L*).
3. **Inventory** (SPEC-029): Mengelola katalog barang fisik bersaldo, jasa/layanan non-stok, satuan ukuran, dan penyesuaian stok.

Namun, di dunia nyata bisnis tidak berjalan di ruang hampa. Terjadi jurang operasional (*missing bridge*) ketika entitas ingin menjual barang/jasa kepada pelanggan atau menerima tagihan dari vendor:
1. **Fragmentasi Transaksi Bisnis**: Tidak ada entitas dokumen formal yang merekatkan *Contact* (siapa yang bertransaksi), *Item* (apa yang dibeli/dijual beserta kuantitas & harga), dan *Finance* (bagaimana dan kapan dibayar).
2. **Ketiadaan Dukungan Multi-Mode Transaksi**:
   - Model **Kasir Cepat (Point-of-Sale / POS Checkout)**: Pelanggan membeli barang/jasa di tempat, membayar lunas seketika, stok terpotong seketika, kas bertambah seketika, dan kuitansi dicetak instan.
   - Model **Faktur Tempo (Accounts Receivable / AR Billing)**: Invoice diterbitkan (Status: *Issued*) dengan tanggal jatuh tempo (*due date*). Pembayaran dapat dicicil (*partially paid*) atau lunas di kemudian hari (*paid*).
3. **Kalkulasi Nilai dan Jejak Audit**: Potensi salah hitung subtotal, diskon per item / diskon faktur, pajak (e.g., PPN 11%), serta ketiadaan jejak audit atas pelunasan parsial (*payment receipt*).
4. **Kebutuhan Cetak Dokumen Terstandarisasi**: Faktur tagihan A4/Letter profesional dan struk kuitansi termal (*thermal receipt*) yang dapat di-generate otomatis via core document engine.

Solusinya adalah membangun **Modul Level 2: `Billing` (Universal Invoicing, Billing & Document Engine)** yang menyatukan Contact, Inventory, dan Finance ke dalam satu siklus hidup dokumen transaksi terpadu, multi-tenant, dan teruji secara atomik.

---

## 2. Goals & Non-Goals

### Goals
- **Model Dokumen Transaksi Universal (`invoices` & `invoice_items`)**:
  - Mendukung tipe dokumen: `invoice` (Faktur Penjualan / Piutang Usaha ke Pelanggan) dan `bill` (Tagihan Pembelian / Hutang Usaha dari Vendor).
  - Snapshot data kontak (`contact_id`, `contact_name`) dan rincian item (`item_id`, `item_name`, `item_type`, `unit_price`, `quantity`, `discount`, `tax_rate`, `subtotal`).
  - Penomoran otomatis berurutan tahunan via `sequence('invoice', 'INV', 5, 'yearly')` (e.g., `INV/2026/00001`) dan `sequence('bill', 'BILL', 5, 'yearly')`.
  - Kalkulasi keuangan akurat: Subtotal, Total Diskon, Total Pajak, Grand Total, Jumlah Terbayar (*amount_paid*), dan Sisa Tagihan (*amount_due*).
  - Status siklus hidup: `draft`, `issued`, `partially_paid`, `paid`, `cancelled`.
- **Pencatatan Pembayaran & Kuitansi (`invoice_payments`)**:
  - Rekam pembayaran parsial maupun penuh dengan nomor kuitansi otomatis via `sequence('payment_receipt', 'RCP', 5, 'yearly')` (e.g., `RCP/2026/00001`).
  - Metode pembayaran: `cash`, `bank_transfer`, `qris`, `card`, `other`.
  - Integrasi otomatis ke `finance()`: Saat pembayaran dicatat dan akun kas/bank dipilih, otomatis membukukan transaksi kas masuk/keluar ke akun keuangan terkait!
- **Dukungan Dua Mode Operasional Transaksi**:
  - **Mode Kasir Cepat / Quick POS Checkout**: 1 langkah atomik (buat invoice, set status paid, kurangi stok barang via `inventory()`, rekam kas masuk via `finance()`, dan hasilkan kuitansi).
  - **Mode Faktur Tempo / AR Workflow**: Draft $\rightarrow$ Issued (potong stok) $\rightarrow$ Multi-Payment (rekam cicilan/pelunasan) $\rightarrow$ Paid.
- **Integrasi Otomatis ke Modul Level 2**:
  - **Inventory**: Validasi ketersediaan stok (`checkAvailability`) dan mutasi stok otomatis bertipe `out` saat invoice diterbitkan/kasir selesai, serta *revert stock* jika invoice dibatalkan. Item bertipe `service` tidak terhambat stok.
  - **Finance**: Pencatatan mutasi kas otomatis ke akun kas/bank tenant (`finance()->recordCashFlow`).
  - **Contact**: Lookup dan linking otomatis data pelanggan/vendor.
- **Exported Shared Contract Interface (`BillingRegistryInterface`)**:
  - Global helper: `billing(): BillingRegistryInterface` dan `invoices(): BillingRegistryInterface`.
  - Fungsi instan: `createInvoice()`, `getInvoice()`, `issueInvoice()`, `recordPayment()`, `quickCheckout()`, `cancelInvoice()`, `listInvoices()`, `getBillingSummary()`, `generateReceipt()`.
- **Web UI & REST API**:
  - Web UI untuk daftar invoice, form kasir cepat / faktur baru, detail tagihan, dan rekaman pembayaran.
  - REST API endpoint untuk integrasi transaksi programatik (mobile POS, frontend kasir, webhook checkout).

### Non-Goals
- Tidak mencakup sistem multi-mata uang dinamis dengan kurs forex live per detik (menggunakan default mata uang tenant e.g. IDR).
- Tidak mencakup sistem pemungutan faktur pajak elektronik negara khusus (seperti e-Faktur DJP XML/CSV export spesifik) pada rilis Level 2 ini.

---

## 3. Technical Architecture & Design

### Alur Integrasi Antar-Modul
```
┌────────────────────────────────────────────────────────────────────────┐
│                        app/Modules/Billing/                            │
│                                                                        │
│  [Quick Checkout / POS]              [Faktur Tempo / AR Invoicing]     │
│           │                                       │                    │
│           ▼                                       ▼                    │
│   createInvoice() ───────────────────────► issueInvoice()              │
│           │                                       │                    │
│           ├───► Deduct Stock via items()->adjustStock('out')           │
│           │                                       │                    │
│           ▼                                       ▼                    │
│   recordPayment() ───────────────────────► recordPayment()             │
│           │                                       │                    │
│           └───► Record Cash Flow via finance()->recordCashFlow()       │
│           │                                                            │
│           ▼                                                            │
│   generateReceipt() ──► docs()->renderHtml() / Struk Kasir             │
└────────────────────────────────────────────────────────────────────────┘
```

### Data Model & Database Schema

```
┌────────────────────────────────────────────────────────────────────────┐
│                                invoices                                │
├────────────────────────────────────────────────────────────────────────┤
│ id (PK, int)                                                           │
│ tenant_id (FK, int)                                                    │
│ invoice_number (varchar, unique per tenant) e.g. INV/2026/00001        │
│ type ('invoice' | 'bill')                                              │
│ contact_id (FK contacts, nullable)                                     │
│ contact_name (varchar)                                                 │
│ status ('draft' | 'issued' | 'partially_paid' | 'paid' | 'cancelled')  │
│ issue_date (date/varchar)                                              │
│ due_date (date/varchar, nullable)                                      │
│ currency (varchar, default 'IDR')                                      │
│ subtotal (decimal/float, default 0)                                    │
│ discount_total (decimal/float, default 0)                              │
│ tax_total (decimal/float, default 0)                                   │
│ grand_total (decimal/float, default 0)                                 │
│ amount_paid (decimal/float, default 0)                                 │
│ amount_due (decimal/float, default 0)                                  │
│ payment_status ('unpaid' | 'partially_paid' | 'paid')                  │
│ notes (text, nullable)                                                 │
│ created_by (int, nullable)                                             │
│ created_at, updated_at                                                 │
└──────────────────┬─────────────────────────────────┬───────────────────┘
                   │ 1:N                             │ 1:N
                   ▼                                 ▼
┌────────────────────────────────────┐ ┌─────────────────────────────────┐
│           invoice_items            │ │        invoice_payments         │
├────────────────────────────────────┤ ├─────────────────────────────────┤
│ id (PK, int)                       │ │ id (PK, int)                    │
│ tenant_id (FK, int)                │ │ tenant_id (FK, int)             │
│ invoice_id (FK invoices)           │ │ invoice_id (FK invoices)        │
│ item_id (FK items, nullable)       │ │ payment_number (varchar, unique)│
│ item_name (varchar)                │ │ account_id (FK finance_accounts)│
│ item_type ('goods'|'service'|'etc')│ │ cash_flow_id (FK cash_flows,null)
│ quantity (decimal/float)           │ │ payment_method (varchar)        │
│ unit_price (decimal/float)         │ │ amount (decimal/float)          │
│ discount (decimal/float, default 0)│ │ payment_date (date/varchar)     │
│ tax_rate (decimal/float, default 0)│ │ reference_note (text, nullable) │
│ subtotal (decimal/float)           │ │ created_by (int, nullable)      │
│ created_at, updated_at             │ │ created_at, updated_at          │
└────────────────────────────────────┘ └─────────────────────────────────┘
```

### Shared Contract Interface (`BillingRegistryInterface`)
```php
namespace App\Modules\Billing\Contracts;

interface BillingRegistryInterface
{
    public function createInvoice(array $data): array;
    public function getInvoice(int $id): ?array;
    public function updateInvoice(int $id, array $data): array;
    public function issueInvoice(int $id, bool $deductInventory = true): array;
    public function recordPayment(int $invoiceId, array $paymentData): array;
    public function quickCheckout(array $data): array;
    public function cancelInvoice(int $id, ?string $reason = null, bool $revertStock = true): array;
    public function listInvoices(array $filters = []): array;
    public function getBillingSummary(): array;
    public function generateReceipt(int $invoiceId): array;
}
```

---

## 4. Edge Cases & Error Scenarios
- **Kasus 1: Stok Barang Tidak Mencukupi saat Issue/Checkout**: Jika ada item fisik (`type === 'goods'`) yang stoknya kurang dari kuantitas order, sistem melempar `InvalidArgumentException` tanpa mengurangi stok parsial (transaksi atomik). Item non-fisik (`service`) tidak terpengaruh batas stok.
- **Kasus 2: Pembayaran Melebihi Sisa Tagihan (Overpayment)**: `recordPayment()` menolak pencatatan jika nominal bayar lebih besar dari `amount_due` (kecuali flag toleransi kembalian pada quick checkout kasir).
- **Kasus 3: Pembatalan Invoice yang Sudah Terbit (Revert Stock)**: Jika invoice dibatalkan (`cancelInvoice`), stok fisik barang yang sebelumnya dipotong akan dikembalikan via `inventory()->adjustStock(['type' => 'in', ...])` secara otomatis.
- **Kasus 4: Isolasi Multi-Tenant Mutlak**: Seluruh query `invoices`, `invoice_items`, dan `invoice_payments` terlindungi oleh `tenant_id` aktif untuk mencegah kebocoran data antar-tenant.

---

## 5. Test Strategy
- **Unit & Integration Test Suite** (`tests/Unit/BillingModuleTest.php`):
  1. `test_can_create_draft_invoice_with_line_items_and_correct_totals`
  2. `test_cannot_issue_invoice_if_physical_item_stock_insufficient`
  3. `test_issuing_invoice_deducts_inventory_stock_for_goods`
  4. `test_record_partial_and_full_payment_updates_invoice_status_and_records_finance_cash_flow`
  5. `test_quick_checkout_atomically_creates_invoice_deducts_stock_and_records_finance`
  6. `test_cannot_overpay_invoice`
  7. `test_cancelling_issued_invoice_reverts_inventory_stock`
  8. `test_multi_tenant_isolation_in_billing_registry`
  9. `test_billing_summary_aggregates_total_billed_received_and_due`
- **Verification Gate**:
  - PHPUnit test suite 100% pass.
  - TypeScript typecheck (`tsc --noEmit`) 100% clean.
  - Pre-commit verification (`scripts/verify.ps1`).

---

## 6. Atomic Task Checklist

- [x] **Phase 1: Setup Modul, Arsitektur, & Database Migrations**
  - [x] 1.1 Buat manifest modul `app/Modules/Billing/module.json`.
  - [x] 1.2 Buat migration `app/Modules/Billing/Database/Migrations/create_billing_tables.php` untuk tabel `invoices`, `invoice_items`, dan `invoice_payments`.
  - [x] 1.3 Jalankan migrasi via console runner dan verifikasi skema database.
- [x] **Phase 2: Service Contracts & Core Engine Implementation (TDD)**
  - [x] 2.1 Buat interface `App\Modules\Billing\Contracts\BillingRegistryInterface`.
  - [x] 2.2 Buat Service Provider `App\Modules\Billing\BillingServiceProvider` dan daftarkan ke modul.
  - [x] 2.3 Daftarkan helper global `billing()` dan `invoices()` di `core/helpers.php`.
  - [x] 2.4 Tulis test suite `tests/Unit/BillingModuleTest.php` mencakup pembuatan draft, issue, potong stok, pembayaran, integrasi finance, dan quick checkout.
  - [x] 2.5 Implementasikan `App\Modules\Billing\Services\BillingService` hingga seluruh skenario unit test lolos 100%.
- [ ] **Phase 3: Web UI, Routes, & Programmatic REST API**
  - [ ] 3.1 Buat routes modul di `app/Modules/Billing/routes.php`.
  - [ ] 3.2 Implementasikan Web Controller `BillingController` untuk tampilan daftar tagihan, form faktur/kasir, dan cetak kuitansi.
  - [ ] 3.3 Implementasikan REST API Controller `ApiBillingController` untuk integrasi POS/checkout terprogram.
  - [ ] 3.4 Buat view template atau halaman UI responsif dark/light mode untuk kasir dan invoice.
- [ ] **Phase 4: Final Verification, Review & Handoff**
  - [ ] 4.1 Jalankan seluruh test suite global via `vendor/bin/phpunit.bat` (memastikan tidak ada regresi).
  - [ ] 4.2 Jalankan `npm run typecheck` dan script `scripts/verify.ps1`.
  - [ ] 4.3 Perbarui `specs/README.md` dan `.agents/SESSION_HANDOFF.md`.
  - [ ] 4.4 Merge atomic commit ke branch `main`.
