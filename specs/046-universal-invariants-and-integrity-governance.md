# SPEC-046: Universal Invariants & Cross-Entity Integrity Governance

- **Status**: `Completed`
- **Owner / Author**: Antigravity & Lead Architect
- **Tanggal Dibuat**: 2026-10-29
- **Target File / Modul**: `core/Reconciliation/`, `tests/Unit/`, `tests/Integration/`

---

## 1. Problem Statement & Context

Selama pengembangan fondasi Core (Level 1) dan Modul Shared (Level 2), sistem telah memiliki berbagai primitif integritas (Ledger berpasangan, Mutex konkuren, Outbox andal, Crypto-Shredder, Audit Log, dan Reconciliation Engine). Namun, pengujian dan pengawasan integritas sebelumnya masih berfokus parsial pada entitas komersial/klinik dan belum dirumuskan ke dalam konstitusi invarian universal yang mencakup entitas profit maupun non-profit (seperti posko bencana, yayasan sosial dhuafa, atau layanan berbasis dana hibah/zakat).

Selain itu, audit celah sebelumnya (`core_gap_audit.md`) mencatat bahwa:
1. `ReconciliationEngine` (SPEC-039) baru memiliki 5 auditor dasar (`FinanceBilling`, `InventoryStock`, `TariffSplit`, `CryptographicSeal`, `TenantReferential`). Belum ada pengawasan otomatis untuk *Monotonisitas Status*, *Kebekuan Buku Tertutup*, *Kompensasi Non-Destruktif*, dan *Validitas Batas Domain*.
2. Pengujian `ReconciliationEngineTest` masih menggunakan DDL mock manual, bukan skema migrasi resmi platform (`Migrator::runMigrationsFrom()`), yang rentan terhadap *schema drift* (temuan G-13).

---

## 2. Goals & Non-Goals

### Goals
1. **Kodifikasi Konstitusi 23 Invarian Universal**: Mendokumentasikan secara formal 23 Invarian Universal dalam 5 lapisan integritas (Fisik/Ekonomi, Kepercayaan/Hukum, Waktu/Status, Distribusi/Eksekusi, dan Survival Mesin) serta 5 mitigasi benturan (*envelope crypto-shredding*, *two-date ledger*, *key-space partitioning*, *outbox coalescing*, dan *explicit adjustment mutation*).
2. **Ekspansi Auditor `ReconciliationEngine` (SPEC-039 Upgrade)**:
   - Menambahkan `StateMonotonicityAuditor`: Mendeteksi anomali siklus hidup (misal: entitas `final`/`cancelled` melompat mundur ke `draft`/`active`).
   - Menambahkan `ClosedPeriodAuditor`: Mendeteksi mutasi yang menyusup ke periode fiskal/operasional yang telah berstatus `closed`.
   - Menambahkan `DomainBoundaryAuditor`: Mendeteksi nilai numerik/temporal di luar nalar fisis (diskon $> 100\%$, persentase negatif, tanggal masa depan invalid).
3. **Anti-Schema Drift Remediation (G-13)**:
   - Memutakhirkan `tests/Unit/ReconciliationEngineTest.php` agar mengeksekusi migrasi riil via `\Core\Database\Migrator::runMigrationsFrom()`.
4. **Universal Invariant Multi-Entity Test Suite**:
   - Membangun suite pengujian `tests/Unit/UniversalInvariantsTest.php` yang menguji integritas invarian pada model operasional **Profit** (faskes komersial, apotek) dan **Non-Profit** (layanan dhuafa, donasi barang, dana terikat).
5. **Kepatuhan Ponytail Pragmatism**:
   - Murni menggunakan native PHP 8.3 dan SQL baku, tanpa dependensi paket baru, tanpa modifikasi modul Level 3.

### Non-Goals
- Tidak membuat modul aplikasi Level 3 (EMR, Rekam Medis Elektronik).
- Tidak mengubah API publik atau kontrak antarmuka modul bisnis Level 2 yang sudah stabil.

---

## 3. Technical Architecture & Design

### Lapisan Invarian & 3 Gerbang Eksekusi (Tiering)

```
[Transaksi Masuk]
       │
       ▼
┌────────────────────────────────────────────────────────┐
│ Gerbang 1: Sinkronus Real-Time (< 5ms)                 │
│ - Invarian 1, 2, 3, 11, 13, 16, 17, 19                 │
│ - Validasi batas domain, debit=kredit, row lock mutex  │
└────────────────────────────────────────────────────────┘
       │
       ▼
┌────────────────────────────────────────────────────────┐
│ Gerbang 2: Asinkronus Outbox (< 500ms)                 │
│ - Invarian 6, 8, 9, 12, 14                             │
│ - Hash-chain audit, segel HMAC, outbox dispatch        │
└────────────────────────────────────────────────────────┘
       │
       ▼
┌────────────────────────────────────────────────────────┐
│ Gerbang 3: Inspeksi Periodik / On-Demand (SPEC-039)    │
│ - ReconciliationManager -> runFullAudit()              │
│ - Invarian 4, 5, 7, 10, 15, 18, 20, 21, 22, 23         │
│ - Deteksi kebocoran tenant, buku tutup, bit-rot, drift │
└────────────────────────────────────────────────────────┘
```

### Ekstensi Auditor Baru di `core/Reconciliation/Auditors/`

1. **`StateMonotonicityAuditor`**:
   - Menelusuri log status dan relasi status saat ini.
   - Memastikan transisi mematuhi matriks DAG yang sah: `draft` $\to$ `pending` $\to$ `active` $\to$ `completed` / `cancelled`.
2. **`ClosedPeriodAuditor`**:
   - Memeriksa tabel `fiscal_periods` (atau metadata periode akuntansi).
   - Memastikan tidak ada baris transaksi (`financial_transactions`, `journal_entries`, `inventory_movements`) dengan `transacted_at` di dalam periode `closed` yang memiliki `created_at` setelah periode tersebut ditutup tanpa flag penyesuaian.
3. **`DomainBoundaryAuditor`**:
   - Memeriksa integritas nilai batas (misal: `discount_percent BETWEEN 0 AND 100`, `quantity > 0`, `tax_rate >= 0`).

---

## 4. Edge Cases & Error Scenarios

1. **Edge Case 1: Transaksi Masuk dari Node Offline ke Periode Tertutup**:
   - *Penanganan*: Sesuai mitigasi *Two-Date Ledger*, `transacted_at` tetap mempertahankan tanggal fisik, namun `posted_at` dialokasikan ke periode aktif berjalan dengan penanda `prior_period_adjustment`.
2. **Edge Case 2: Hak Dilupakan (UU PDP) Terhadap Entitas yang Memiliki Rantai Audit**:
   - *Penanganan*: Sesuai mitigasi *Envelope Crypto-Shredding*, DEK subjek dihancurkan di `tenant_crypto_keys`, data dienkripsi ulang menjadi string teracak permanen, rantai hash audit tetap tidak rusak.
3. **Edge Case 3: Retur/Pembatalan pada Buku Besar yang Menggunakan Skema Append-Only**:
   - *Penanganan*: Dilarang melakukan DML `DELETE`. Sistem mewajibkan pencatatan baris mutasi berlawanan arah (`credit_note`, `reversal_entry`, `stock_return_in`).

---

## 5. Test Strategy

1. **Unit Test**:
   - Pengujian auditor baru: `StateMonotonicityAuditorTest`, `ClosedPeriodAuditorTest`, `DomainBoundaryAuditorTest`.
   - Pembaruan `ReconciliationEngineTest` dengan skema migrasi resmi.
2. **Integration Test (`UniversalInvariantsTest`)**:
   - Uji Invarian Finansial & Konservasi Fisik pada Entitas Profit: Pembayaran kasir, alokasi split fee, pemotongan stok FEFO.
   - Uji Invarian pada Entitas Non-Profit: Donasi masuk, bantuan sembako/obat keluar bernilai Rp 0, alokasi dana terikat, hak operasional relawan lapangan.
   - Uji Invarian Kedaulatan Tenant: Verifikasi zero cross-tenant leakage dan no orphan records pada skema penuh.

---

## 6. Atomic Task Checklist

- [x] **Phase 1: Dokumentasi Konstitusi & Kontrak Auditor**
  - [x] 1.1 Buat dokumen Konstitusi Invarian Universal di `.agents/rules/10-universal-invariants-charter.md`.
  - [x] 1.2 Daftarkan aturan baru di [`AGENTS.md`](file:///c:/aplikasi/aio/AGENTS.md).
- [x] **Phase 2: Implementasi Auditor Baru di Core Reconciliation**
  - [x] 2.1 Buat `StateMonotonicityAuditor` di `core/Reconciliation/Auditors/StateMonotonicityAuditor.php`.
  - [x] 2.2 Buat `ClosedPeriodAuditor` di `core/Reconciliation/Auditors/ClosedPeriodAuditor.php`.
  - [x] 2.3 Buat `DomainBoundaryAuditor` di `core/Reconciliation/Auditors/DomainBoundaryAuditor.php`.
  - [x] 2.4 Daftarkan ketiga auditor baru ke dalam `ReconciliationManager::__construct()`.
- [x] **Phase 3: Anti-Schema Drift Remediation (G-13) & Invariant Test Suite**
  - [x] 3.1 Refactor `tests/Unit/ReconciliationEngineTest.php` menggunakan `Migrator::runMigrationsFrom()`.
  - [x] 3.2 Buat suite pengujian `tests/Unit/UniversalInvariantsTest.php` mencakup skenario Profit & Non-Profit.
- [x] **Phase 4: Verifikasi Menyeluruh & Git Discipline**
  - [x] 4.1 Jalankan seluruh PHPUnit test suite (target: 100% PASS).
  - [x] 4.2 Jalankan `npm run typecheck` untuk memastikan integritas frontend.
  - [x] 4.3 Perbarui [`.agents/SESSION_HANDOFF.md`](file:///c:/aplikasi/aio/.agents/SESSION_HANDOFF.md) dan buat atomic commit Git.
