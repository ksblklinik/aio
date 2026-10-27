# SPEC-022: Unified Payment Gateway Driver Contract & Quota Entitlements Engine

- **Status**: `In Progress`
- **Owner / Author**: Antigravity Platform Team
- **Tanggal Dibuat**: 2026-10-27
- **Target File / Modul**: `core/Payment/`, `core/Billing/`, `core/helpers.php`, `database/migrations/`

---

## 1. Problem Statement & Context
Dalam arsitektur modular multi-tenant aio, hampir seluruh aplikasi bisnis (e-commerce toko online, sistem donasi organisasi/yayasan, iuran anggota kas, canvassing order penagihan lapangan, agregator driver top-up dompet, serta model bisnis SaaS) membutuhkan 2 kapabilitas fundamental yang seragam:
1. **Pemrosesan Pembayaran (Payment Gateway)**: Tanpa standardisasi di Core, setiap modul bisnis akan mengimplementasikan API vendor secara sporadis (Midtrans, Kasera-pay, transfer manual, Xendit), menangani callback webhook tanpa verifikasi signature yang seragam, dan gagal merekonsiliasi pembayaran secara atomik ke buku besar akuntansi (`LedgerManager`).
2. **Pengawasan Kuota & Fitur (Quota & Feature Entitlement)**: Aplikasi multi-tenant memerlukan pembatasan akses fitur (*feature gating*) dan pembatasan pemakaian sumber daya (*usage metering*) sesuai paket langganan (Free, Starter, Pro) seperti batas jumlah invoice per bulan, kuota pesan WhatsApp, jumlah pengguna per tenant, atau akses ke fitur analitik AI.

Menempatkan kedua kapabilitas ini di Core mencegah *Infrastructure Leakage* (Rule 08) dan menjamin semua modul bisnis dapat bertransaksi serta menerapkan monetisasi secara konsisten.

---

## 2. Goals & Non-Goals

### Goals
- **Unified Payment Driver Contract (`Core\Payment\PaymentDriverInterface`)**:
  - Kontrak seragam untuk inisiasi penagihan (`createCharge`), verifikasi webhook callback (`verifyWebhook`), pengecekan status (`checkStatus`), dan pembatalan/pengembalian (`refund`).
  - Driver bawaan offline-first: `MockPaymentDriver` (simulasi transaksi instan/pending/gagal/webhook tanpa internet), `ManualTransferDriver` (konfirmasi transfer bank manual), dan `KaseraPaymentDriver` / `GenericGatewayDriver`.
  - Multi-tenant config aware: Konfigurasi kredensial gateway dapat dispesifikasikan per-tenant atau secara global platform.
  - Rekonsiliasi atomik otomatis: Integrasi opsional dengan `LedgerManager` saat status pembayaran menjadi `PAID` untuk mencatat mutasi jurnal double-entry secara idempotence.
- **Quota & Feature Entitlement Engine (`Core\Billing\QuotaManager`)**:
  - **Feature Gating**: Pengecekan izin fitur boolean (`quota()->can('export_excel')`, `quota()->can('ai_assistant')`).
  - **Usage Quota Metering**: Pembatasan kuota konsumsi numerik (`quota()->consume('monthly_invoices', 1)`), pengecekan sisa kuota (`quota()->remaining('monthly_invoices')`), dan auto-reset periode (harian, bulanan, tahunan, atau lifetime).
  - Isolasi multi-tenant yang ketat dan efisien dengan fallback nilai default.
- **Global Helper Functions**:
  - `payment(?string $driver = null)` untuk inisiasi pembayaran.
  - `quota(?int $tenantId = null)` untuk pengecekan entitlement dan metering kuota.

### Non-Goals
- Tidak mengikat platform ke salah satu vendor berbayar spesifik: implementasi driver menggunakan pola pluggable driver.
- Tidak menangani rendering halaman checkout UI khusus (modul bisnis yang merender tampilan UI sesuai domainnya).

---

## 3. Technical Architecture & Design

### Alur Data Pembayaran & Ledger
```
[Modul Bisnis] ──> payment()->charge($req) ──> [Payment Driver (Mock/Kasera/Midtrans)]
                                                        │
                                                        ▼
                                              Return PaymentResponse (Checkout URL/QR/Ref)
                                                        │
[Gateway Webhook] ──> payment()->handleWebhook($data) ──┤
                                                        ▼
                                              Update Status: PAID
                                                        │
                                                        ▼ (Optional)
                                              LedgerManager::recordMutation()
```

### Alur Entitlement & Quota Metering
```
[Modul Bisnis] ──> quota()->consume('orders', 1) ──> [QuotaManager]
                                                            │
                                         ┌──────────────────┴──────────────────┐
                                         ▼                                     ▼
                                  [Check Feature]                     [Check & Increment Meter]
                              Is 'orders' allowed?                  Usage < Limit?
                                      │                                        │
                                      ▼                                        ▼
                                  Return bool                       Increment & Return bool
```

### Data Model & Migration (`2026_10_27_000019_create_payment_and_quota_tables.sql`)
1. **`payment_transactions`**:
   - `id` INTEGER PRIMARY KEY AUTOINCREMENT
   - `tenant_id` INTEGER NOT NULL
   - `reference_no` VARCHAR(64) UNIQUE NOT NULL
   - `driver` VARCHAR(32) NOT NULL
   - `amount` DECIMAL(15,2) NOT NULL
   - `currency` VARCHAR(3) DEFAULT 'IDR'
   - `status` VARCHAR(20) DEFAULT 'pending' -- pending, paid, failed, expired, refunded
   - `payment_method` VARCHAR(32) NULL -- qris, va_bca, manual_transfer, cc, etc.
   - `customer_name` VARCHAR(150) NULL
   - `customer_email` VARCHAR(150) NULL
   - `customer_phone` VARCHAR(32) NULL
   - `gateway_reference` VARCHAR(128) NULL
   - `checkout_url` TEXT NULL
   - `metadata` TEXT NULL -- JSON metadata & ledger account target
   - `paid_at` DATETIME NULL
   - `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
   - `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP

2. **`tenant_entitlements`**:
   - `id` INTEGER PRIMARY KEY AUTOINCREMENT
   - `tenant_id` INTEGER NOT NULL
   - `feature_key` VARCHAR(64) NOT NULL
   - `is_enabled` INTEGER DEFAULT 1
   - `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
   - `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
   - UNIQUE(`tenant_id`, `feature_key`)

3. **`tenant_quotas`**:
   - `id` INTEGER PRIMARY KEY AUTOINCREMENT
   - `tenant_id` INTEGER NOT NULL
   - `metric_key` VARCHAR(64) NOT NULL
   - `quota_limit` INTEGER NOT NULL -- -1 untuk unlimited
   - `current_usage` INTEGER DEFAULT 0
   - `period` VARCHAR(20) DEFAULT 'monthly' -- lifetime, daily, monthly, yearly
   - `period_start` DATETIME NOT NULL
   - `period_end` DATETIME NULL
   - `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
   - UNIQUE(`tenant_id`, `metric_key`)

---

## 4. Edge Cases & Error Scenarios
- **Kasus 1**: Webhook dikirim ganda oleh payment gateway (duplicate callback).
  - *Mitigasi*: Cek status transaksi saat ini. Jika sudah `paid`, return success 200 tanpa mengeksekusi ulang ledger mutation (idempotent webhook handling).
- **Kasus 2**: Pemakaian kuota melebihi limit (`current_usage >= quota_limit`).
  - *Mitigasi*: `consume()` mengembalikan `false` tanpa menaikkan counter. Sediakan method `remaining()` dan `isExceeded()` untuk notifikasi proaktif kepada pengguna.
- **Kasus 3**: Periode kuota bulanan kedaluwarsa saat transaksi baru masuk.
  - *Mitigasi*: Auto-reset siklus (`period_start` diperbarui ke awal periode baru, `current_usage` direset ke 0 sebelum konsumsi baru dicatat).
- **Kasus 4**: Driver gateway pihak ketiga offline atau request network timeout.
  - *Mitigasi*: Exception tertangani dengan rapi, status transaksi tetap tersimpan dengan flag `failed` atau `pending`, tanpa menghentikan runtime aplikasi.

---

## 5. Test Strategy
- **Unit Testing**:
  - `CorePaymentAndQuotaTest`:
    - Menguji pembuatan charge transaksi via `MockPaymentDriver` dan `ManualTransferDriver`.
    - Menguji verifikasi webhook dan pembaruan status `paid`.
    - Menguji rekonsiliasi otomatis ke `LedgerManager` bila metadata ledger diberikan.
    - Menguji feature gating boolean `can()`.
    - Menguji quota consumption `consume()`, batas kuota `remaining()`, auto-reset periode, dan pencegahan overflow.
- **Integration & Regression Testing**:
  - Jalankan `./scripts/verify.ps1` untuk memvalidasi test suite global (target 290+ tests, 100% pass) dan `tsc --noEmit`.

---

## 6. Atomic Task Checklist

- [ ] **Phase 1: Database Migration & Contracts**
  - [ ] 1.1 Buat skema migrasi `2026_10_27_000019_create_payment_and_quota_tables.sql` untuk transaksi pembayaran, feature entitlements, dan tenant quotas.
  - [ ] 1.2 Jalankan migrasi ke `database.sqlite`.
  - [ ] 1.3 Buat interface `Core\Payment\PaymentDriverInterface`, DTO `PaymentRequest`, `PaymentResponse`, dan `WebhookResult`.
- [ ] **Phase 2: Payment Gateway Engine**
  - [ ] 2.1 Buat driver `MockPaymentDriver` untuk simulasi offline-first.
  - [ ] 2.2 Buat driver `ManualTransferDriver` untuk alur bukti transfer / verifikasi manual.
  - [ ] 2.3 Buat manager `Core\Payment\PaymentManager` dengan registrasi driver, resolusi multi-tenant, dan rekonsiliasi ledger otomatis.
- [ ] **Phase 3: Quota & Entitlement Engine**
  - [ ] 3.1 Buat service `Core\Billing\QuotaManager` dengan feature gating boolean dan numeric usage metering dengan auto-reset siklus.
  - [ ] 3.2 Tambahkan helper functions `payment()` dan `quota()` ke `core/helpers.php`.
- [ ] **Phase 4: Unit Testing & Self-Verification**
  - [ ] 4.1 Buat unit test komprehensif di `tests/Unit/CorePaymentAndQuotaTest.php`.
  - [ ] 4.2 Jalankan `./scripts/verify.ps1`, pastikan seluruh test suite lolos 100%.
  - [ ] 4.3 Buat git commit atomik dan merge branch fitur ke `main`.
  - [ ] 4.4 Perbarui dokumentasi spec dan `SESSION_HANDOFF.md`.
