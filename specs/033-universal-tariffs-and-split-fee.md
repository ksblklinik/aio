# SPEC-033: Universal Tariffs, Multi-Payer Price Books & Split Fee Engine

- **Status**: `Draft`
- **Owner / Author**: Antigravity Platform Team
- **Tanggal Dibuat**: 2026-10-28
- **Target Modul**: `app/Modules/Tariff/`
- **Level Arsitektur**: Level 2 (Universal Shared Business Foundation)

---

## 1. Problem Statement & Context

Pada implementasi modul `Billing` (SPEC-030) dan `Inventory` (SPEC-029), penentuan harga transaksi barang/layanan masih mengacu pada harga dasar tunggal (`base_price`) atau input manual pada invoice. 

Dalam operasional bisnis riil yang kompleks—seperti **Fasilitas Pelayanan Kesehatan / FKTP / Klinik**, **Distribusi & Ritel Grosir**, **Bengkel / Service Center**, **Firma Konsultan**, hingga **Lembaga Sosial/Zakat**:
1. **Harga Bersifat Multi-Payer & Multi-Tier**: Harga layanan atau barang berbeda tergantung siapa penjamin atau pembelinya:
   - Pasien Umum (Tarif reguler).
   - Pasien BPJS Kesehatan (Tarif kapitasi/non-kapitasi / INA-CBGs).
   - Asuransi Swasta / Korporasi Rekanan (Tarif kontrak khusus dengan plafon coverage dan co-payment pasien).
   - Pelanggan Ritel vs Grosir vs Reseller VIP.
2. **Komposisi Tarif Terbagi (*Component Tariff / Split Fee*)**: Satu transaksi layanan memiliki struktur pecahan biaya internal:
   - Misalnya: Tindakan "Pemeriksaan Dokter Spesialis" seharga Rp 150.000 terbagi atas:
     - **Jasa Medis Dokter**: Rp 100.000 (66.7%).
     - **Jasa Sarana Klinik**: Rp 35.000 (23.3%).
     - **Biaya Administrasi & SIM**: Rp 15.000 (10.0%).
   - Misalnya di Bengkel: Biaya Servis Rp 200.000 terbagi atas Jasa Mekanik 40% dan Jasa Bengkel 60%.
3. **Akrual & Pembayaran Komisi/Jasa (*Fee Settlement*)**: Akumulasi fee jasa dokter, komisi teknisi, atau hak amil harus dapat ditelusuri per transaksi dan dibayarkan secara teratur via jurnal kas/bank di modul `Finance`.

Tanpa modul fondasi Level 2 ini, modul bisnis vertikal (seperti Rekam Medis, Kasir Klinik, atau Manajemen Komisi Sales) akan terpaksa menambal logika tarif dan pembagian honorarium secara ad-hoc (*Infrastructure Leakage*), yang merusak kemurnian arsitektur platform.

---

## 2. Goals & Non-Goals

### Goals
- [ ] Menyediakan **Multi-Payer Price Books Registry** (`tariff_price_books`) untuk mengelola katalog tarif berdasarkan segmen pembeli/penjamin (Umum, BPJS, Asuransi A, Asuransi B, Grosir, Member VIP).
- [ ] Menyediakan **Tariff Rates Matrix** (`tariff_rates`) per item layanan/barang dengan dukungan:
  - Tarif dasar (*base rate*).
  - Tipe kelas fasilitas/ruangan (*room type* misal: VIP, Kelas 1, Standard, Poli).
  - Penjaminan asuransi (*coverage type* flat/persentase, limit plafon, dan co-payment mandiri).
- [ ] Menyediakan **Tariff Component & Split Fee Rules Engine** (`tariff_split_rules`):
  - Memecah harga layanan menjadi komponen penerima (Performer/Dokter/Mekanik, Sarana Fasilitas, Organisasi/Manajemen).
  - Formula perhitungan persentase (`percentage`) maupun nominal tetap (`fixed_amount`).
- [ ] Menyediakan **Fee Allocation & Settlement Tracker** (`tariff_fee_allocations`):
  - Mencatat alokasi bagi hasil riil saat invoice terbit atau dibayar.
  - Memungkinkan pelunasan akumulasi fee (*settlement*) yang terintegrasi langsung dengan jurnal dobel-input di modul `Finance`.
- [ ] Menyediakan Global Helper `tariff(): TariffRegistryInterface` dan REST API endpoints untuk kemudahan integrasi lintas modul.
- [ ] Antarmuka manajemen Web responsif Inertia React (`resources/js/Pages/Tariff/Index.tsx`) dengan tema dark/light mode baku.

### Non-Goals
- Modul ini **TIDAK** memproses klaim elektronik BPJS VClaim/E-Klaim (itu adalah tanggung jawab adaptor bridging eksternal di modul bisnis).
- Modul ini **TIDAK** menggantikan general ledger di modul `Finance`, melainkan bertindak sebagai kalkulator tarif dan penyedia alokasi fee yang men-trigger jurnal akuntansi.

---

## 3. Technical Architecture & Design

### Alur Integrasi Lintas Modul
```
[Billing / Invoice] ────────> [Tariff Engine] <──────── [Contact / Payer]
       │                            │
       │ (1) Resolve Rate           │ (2) Calculate Split
       ▼                            ▼
[Invoice Line Items]        [tariff_fee_allocations]
       │                            │
       │ (3) Payment Settled        │ (4) Fee Disbursement
       ▼                            ▼
[Finance General Ledger] <──────────┘
```

### Data Model & Schema (Database Migrations)

```sql
-- 1. Buku Tarif / Multi-Payer Price Books
CREATE TABLE IF NOT EXISTS tariff_price_books (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    payer_contact_id INTEGER NULL, -- FK ke contacts.id (jika khusus asuransi/perusahaan tertentu)
    currency VARCHAR(10) DEFAULT 'IDR',
    is_active INTEGER DEFAULT 1,
    is_default INTEGER DEFAULT 0,
    valid_from DATETIME NULL,
    valid_until DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (payer_contact_id) REFERENCES contacts(id) ON DELETE SET NULL
);

-- 2. Tarif Layanan & Produk (Rates Matrix)
CREATE TABLE IF NOT EXISTS tariff_rates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    price_book_id INTEGER NOT NULL,
    item_id INTEGER NOT NULL,
    room_type VARCHAR(50) NULL, -- Misal: standard, vip, icu, bengkel_cabang_a
    base_rate DECIMAL(15,2) NOT NULL,
    min_qty DECIMAL(12,2) DEFAULT 1.00,
    coverage_type VARCHAR(30) DEFAULT 'fixed', -- fixed, percentage, cap_limit
    coverage_amount DECIMAL(15,2) NULL, -- Nilai yang ditanggung penjamin
    patient_copay DECIMAL(15,2) DEFAULT 0.00, -- Nilai sisa yang dibayar pasien/klien
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (price_book_id) REFERENCES tariff_price_books(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
);

-- 3. Aturan Pemecahan Tarif / Bagi Hasil (Split Fee Rules)
CREATE TABLE IF NOT EXISTS tariff_split_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    item_id INTEGER NULL, -- Spesifik item atau null jika aturan umum
    price_book_id INTEGER NULL, -- Spesifik buku tarif atau null untuk semua
    name VARCHAR(150) NOT NULL, -- Misal: Jasa Medis Dokter, Jasa Sarana, Komisi Sales
    recipient_type VARCHAR(50) NOT NULL, -- performer, facility, organization, account
    default_beneficiary_contact_id INTEGER NULL, -- FK ke contacts.id (dokter/mekanik default jika ada)
    split_type VARCHAR(30) NOT NULL, -- percentage, fixed_amount
    split_value DECIMAL(15,2) NOT NULL, -- Nilai persentase (e.g. 60.00) atau nominal (e.g. 50000)
    target_account_code VARCHAR(50) NULL, -- Akun beban/hutang di Finance (misal: 2104 - Hutang Jasa Dokter)
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
    FOREIGN KEY (price_book_id) REFERENCES tariff_price_books(id) ON DELETE CASCADE,
    FOREIGN KEY (default_beneficiary_contact_id) REFERENCES contacts(id) ON DELETE SET NULL
);

-- 4. Alokasi Transaksional Bagi Hasil (Fee Allocations per Invoice)
CREATE TABLE IF NOT EXISTS tariff_fee_allocations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    invoice_id INTEGER NOT NULL,
    invoice_item_id INTEGER NULL,
    split_rule_id INTEGER NULL,
    item_id INTEGER NOT NULL,
    beneficiary_contact_id INTEGER NULL, -- Kontak personil yang berhak (dokter/sales/mekanik)
    beneficiary_user_id INTEGER NULL,
    component_name VARCHAR(150) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    status VARCHAR(30) DEFAULT 'accrued', -- accrued, paid, cancelled
    settlement_reference VARCHAR(100) NULL, -- Bukti disbursement/penggajian
    journal_entry_id INTEGER NULL, -- FK ke finance_journals.id saat disbursement
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (invoice_id) REFERENCES billing_invoices(id) ON DELETE CASCADE,
    FOREIGN KEY (split_rule_id) REFERENCES tariff_split_rules(id) ON DELETE SET NULL,
    FOREIGN KEY (beneficiary_contact_id) REFERENCES contacts(id) ON DELETE SET NULL
);
```

### Service Contract (`TariffRegistryInterface.php`)

```php
namespace App\Modules\Tariff\Contracts;

interface TariffRegistryInterface
{
    // Price Books
    public function createPriceBook(array $data): array;
    public function getPriceBook(int $id): ?array;
    public function listPriceBooks(array $filters = []): array;
    public function updatePriceBook(int $id, array $data): array;
    public function deletePriceBook(int $id): bool;

    // Rates Matrix
    public function setRate(int $priceBookId, int $itemId, float $baseRate, array $options = []): array;
    public function getRate(int $priceBookId, int $itemId, ?string $roomType = null): ?array;
    public function listRates(int $priceBookId, array $filters = []): array;
    public function resolvePrice(int $itemId, ?int $priceBookId = null, float $qty = 1.0, array $options = []): array;

    // Split Rules
    public function createSplitRule(array $data): array;
    public function listSplitRules(array $filters = []): array;
    public function deleteSplitRule(int $id): bool;
    public function calculateSplits(int $itemId, float $actualAmount, ?int $priceBookId = null, ?int $performerContactId = null): array;

    // Allocations & Settlement
    public function recordInvoiceSplits(int $invoiceId, array $invoiceItems): array;
    public function listAllocations(array $filters = []): array;
    public function getBeneficiarySummary(int $beneficiaryContactId): array;
    public function settleBeneficiaryFees(int $beneficiaryContactId, array $allocationIds, ?string $paymentAccount = null): array;
}
```

---

## 4. Edge Cases & Error Scenarios

- **Kasus 1: Buku Tarif Tidak Ditemukan / Kadaluwarsa**
  - Jika `priceBookId` tidak valid atau masa berlaku `valid_until` telah lewat, sistem otomatis *fallback* ke buku tarif default (`is_default = 1`) atau harga dasar `base_price` dari katalog item.
- **Kasus 2: Total Split Melebihi 100%**
  - Validasi ketat saat pembuatan `tariff_split_rules`: akumulasi persentase komponen untuk satu item tidak boleh melebihi 100%. Sistem akan melempar `ValidationException`.
- **Kasus 3: Performer Dinamis saat Transaksi**
  - Jika aturan split bertipe `performer`, namun kontak penerima tidak diset secara statis di aturan, sistem secara otomatis mengambil performer yang ditunjuk pada invoice/layanan saat transaksi berlangsung.
- **Kasus 4: Pembatalan / Void Invoice**
  - Jika invoice dibatalkan (`cancelled`), seluruh catatan alokasi fee yang berstatus `accrued` otomatis dibatalkan (`cancelled`) agar tidak dapat dicairkan.

---

## 5. Test Strategy

- **Unit Tests (`tests/Unit/TariffModuleTest.php`)**:
  - Test pembuatan buku tarif multi-penjamin (Umum vs Asuransi vs BPJS).
  - Test resolusi harga bertingkat (*tiered rates*) berdasarkan kuantitas dan kelas ruangan.
  - Test kalkulasi split fee (persentase & nominal flat).
  - Test pencatatan alokasi fee otomatis per invoice.
  - Test settlement/pencairan honorarium akumulatif bagi hasil mitra/dokter.
  - Test isolasi multi-tenant antar workspace.
- **Integration Tests**:
  - Simulasi alur lengkap: Contact Pasien & Penjamin $\rightarrow$ Rate Resolution $\rightarrow$ Billing Invoice $\rightarrow$ Split Allocation $\rightarrow$ Settlement Jurnal Finance.

---

## 6. Atomic Task Checklist

- [ ] **Phase 1: Setup Modul, Arsitektur, & Database Migrations**
  - [ ] 1.1 Buat manifest modul `app/Modules/Tariff/module.json`.
  - [ ] 1.2 Buat migrasi `database/migrations/2026_10_28_000028_create_tariff_and_split_fee_tables.sql`.
  - [ ] 1.3 Jalankan migrasi via console runner dan verifikasi skema database.
- [ ] **Phase 2: Service Contracts & Core Engine Implementation (TDD)**
  - [ ] 2.1 Buat interface `App\Modules\Tariff\Contracts\TariffRegistryInterface`.
  - [ ] 2.2 Buat Service Provider `App\Modules\Tariff\TariffServiceProvider` dan daftarkan modul.
  - [ ] 2.3 Daftarkan helper global `tariff()` di `core/helpers.php`.
  - [ ] 2.4 Tulis test suite `tests/Unit/TariffModuleTest.php` mencakup skenario happy path, edge cases, dan tenant isolation.
  - [ ] 2.5 Implementasikan `App\Modules\Tariff\Services\TariffService` hingga seluruh test lolos 100%.
- [ ] **Phase 3: Web UI, Routes, & Programmatic REST API**
  - [ ] 3.1 Buat routes modul di `app/Modules/Tariff/routes.php`.
  - [ ] 3.2 Implementasikan Web Controller `TariffController` untuk manajemen buku tarif, matriks tarif, dan payout honorarium.
  - [ ] 3.3 Implementasikan REST API Controller `ApiTariffController` untuk resolusi tarif real-time oleh kasir/kiosk.
  - [ ] 3.4 Buat antarmuka pengguna responsif Inertia React `resources/js/Pages/Tariff/Index.tsx` dengan Semantic UI Tokens.
- [ ] **Phase 4: Final Verification, Review & Handoff**
  - [ ] 4.1 Jalankan seluruh test suite global via `vendor/bin/phpunit.bat`.
  - [ ] 4.2 Jalankan `npm run typecheck` dan build aset frontend.
  - [ ] 4.3 Perbarui `specs/README.md` dan `.agents/SESSION_HANDOFF.md`.
  - [ ] 4.4 Merge atomic commit ke branch `main`.
