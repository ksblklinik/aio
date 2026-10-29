# SPEC-048: Expanded Universal Invariants & Cross-Entity Integrity Governance (Invariants 24-30)

- **Status**: `Completed`
- **Owner / Author**: Antigravity & Lead Engineer
- **Tanggal Dibuat**: 2026-10-29
- **Target File / Modul**: `core/Reconciliation/Auditors/*`, `core/Reconciliation/ReconciliationManager.php`, `tests/Unit/ExpandedUniversalInvariantsTest.php`

---

## 1. Problem Statement & Context

Pasca pengesahan Konstitusi 23 Invarian Universal (SPEC-046), audit lapangan terhadap kebutuhan operasional multi-entitas (faskes komersial, apotek peracikan, posko medis tanggap bencana NGO, serta lembaga pengelola dana umat/zakat) mengidentifikasi 7 dimensi integritas esensial yang belum tercakup:
1. **Silsilah Fisik & Rantai Penguasaan (Inv 24)**: Penelusuran pohon silsilah lot induk pada obat racikan/paket bantuan saat terjadi penarikan (*batch recall*).
2. **Integritas Amanah & Dana Terikat (Inv 25)**: Pencegahan subsidi silang ilegal dan defisit pada kantong dana program/titipan pasien.
3. **Kronologi Subjek & Kausalitas Biologis (Inv 26)**: Pencegahan anomali temporal (tindakan mendahului registrasi, dispensing mendahului resep).
4. **Segregasi Tugas & Otorisasi Ganda (Inv 27)**: Penegakan prinsip *maker-checker* pada aksi berdampak kritis (narkotika, penghapusan kerugian).
5. **Karantina Kelaikan & Rantai Mutu (Inv 28)**: Penguncian fisis sumber daya kedaluwarsa, ditarik BPOM, atau putus rantai dingin.
6. **Mandat Persetujuan Tindakan (Inv 29)**: Keabsahan *informed consent* sebelum tindakan invasif/transmisi data ke pihak ketiga.
7. **Bebas Tanggungan Terminasi (Inv 30)**: Larangan penutupan status (*discharge*) jika masih ada kewajiban terbuka (*dangling liabilities*).

---

## 2. Goals & Non-Goals

### Goals
- [x] **Kodifikasi 30 Invarian Universal di Rule 10**: Memperbarui piagam integritas dengan 30 invarian universal, 8 mitigasi benturan, dan 3 tingkatan gerbang eksekusi.
- [x] **Implementasi 3 Auditor Rekonsiliasi Baru di Core (Total 11 Pilar Integritas)**:
  - `ProvenanceLineageAuditor`: Mengaudit pohon silsilah lot induk obat racikan/paket bantuan dan validitas mutasi inventaris.
  - `RestrictedFundAuditor`: Mengaudit saldo kantong dana terikat dan mendeteksi defisit / penyerapan melebihi donasi.
  - `FitnessSafetyAuditor`: Mendeteksi pelanggaran penyaluran barang kedaluwarsa atau dalam status penarikan (*recalled*), serta auto-reconcile status batch kedaluwarsa.
- [x] **Integrasi ke `ReconciliationManager`**: Mendaftarkan ketiga auditor baru secara otomatis pada instansiasi mesin rekonsiliasi default (total 11 pilar terdaftar).
- [x] **Multi-Entity Universal Invariants Test Suite**: Pengujian terpadu untuk model entitas komersial/profit dan yayasan sosial/non-profit dengan 100% skor kelulusan tanpa discrepansi.

### Non-Goals
- Tidak merombak skema tabel modul Level 2 yang sudah stabil; auditor memanfaatkan relasi skema resmi yang sudah ada (`item_batches`, `financial_accounts`, `inventory_movements`, `invoices`, dll.).

---

## 3. Technical Architecture & Design

### Arsitektur 11 Pilar Mesin Rekonsiliasi (`ReconciliationManager`)
```
[ReconciliationManager::audit(?tenantId)]
  ├── 1. FinanceBillingAuditor        (Inv 2 & 11: Tagihan vs Pembayaran Kasir & Buku Kas)
  ├── 2. InventoryStockAuditor        (Inv 1: Stok Fisik vs Kartu Stok & Batches)
  ├── 3. TariffSplitAuditor           (Inv 4: Akumulasi Fee Dokter <= 100%)
  ├── 4. CryptographicSealAuditor     (Inv 6: Integritas Segel Dokumen HMAC SHA-256)
  ├── 5. TenantReferentialAuditor     (Inv 5: Isolasi Tenant & Orphan Records)
  ├── 6. StateMonotonicityAuditor     (Inv 9: DAG Siklus Hidup Status Transaksi)
  ├── 7. ClosedPeriodAuditor          (Inv 10: Kebekuan Periode Akuntansi)
  ├── 8. DomainBoundaryAuditor        (Inv 17: Validasi Batas Nilai Domain & Non-Negativity)
  ├── 9. ProvenanceLineageAuditor     (Inv 24: Silsilah Pohon Lot Induk & Chain of Custody)
  ├── 10. RestrictedFundAuditor       (Inv 25: Integritas Amanah & Dana Terikat Anti-Defisit)
  └── 11. FitnessSafetyAuditor        (Inv 28: Karantina Kelaikan, Recall & Rantai Mutu)
```

---

## 4. Edge Cases & Error Scenarios

1. **Obat Racikan Tanpa Batch Induk Terdaftar**: Diidentifikasi sebagai anomali silsilah putus (*orphan lineage anomaly*) dengan tingkat keparahan `warning`.
2. **Dana Terikat Bernilai Negatif**: Terjadi penyerapan melebihi donasi/hibah masuk. Ditandai sebagai anomali kepatuhan fidusia `critical`.
3. **Pemberian Obat Melewati Tanggal Kedaluwarsa**: Batch terdistribusi saat tanggal transaksi > `expiry_date`. Ditandai sebagai anomali keselamatan pasien `critical`.
4. **Pengecualian Gawat Darurat (Break-Glass Protocol)**: Jika transaksi memiliki penanda `priority = 'cito'` atau `is_emergency = 1`, auditor tidak mengklasifikasikan sebagai pelanggaran fatal melainkan mencatat audit kewajiban telaah peer review.

---

## 5. Test Strategy

1. **Unit Test Spesifik Tiap Auditor**:
   - `ProvenanceLineageAuditorTest`: Verifikasi deteksi lot induk valid vs putus dan mutasi tanpa asal-usul.
   - `RestrictedFundAuditorTest`: Verifikasi deteksi saldo minus dana terikat vs saldo aman.
   - `FitnessSafetyAuditorTest`: Verifikasi deteksi batch expired/quarantine yang terdistribusi dan auto-reconcile expired batch.
2. **Multi-Entity Integration Test (`ExpandedUniversalInvariantsTest`)**:
   - Model 1 (Profit): Klinik bedah swasta, obat paten berizin, tagihan berbayar, fee dokter split, batch aktif.
   - Model 2 (Non-Profit): Posko darurat kemanusiaan, obat donasi, dana terikat gempa, bebas biaya Rp 0, segel BAST digital.
   - Memastikan kedua model mencapai status **100% BALANCED** dengan 0 anomali.

---

## 6. Atomic Task Checklist

- [x] **Phase 1: Rule 10 Codification**
  - [x] 1.1 Perbarui piagam Rule 10 dengan 30 Invarian Universal, 8 Tension Mitigations, dan 3 Tingkatan Eksekusi.
- [x] **Phase 2: New Reconciliation Auditors Implementation**
  - [x] 2.1 Implementasi `ProvenanceLineageAuditor.php` (Inv 24).
  - [x] 2.2 Implementasi `RestrictedFundAuditor.php` (Inv 25).
  - [x] 2.3 Implementasi `FitnessSafetyAuditor.php` (Inv 28).
  - [x] 2.4 Daftarkan seluruh auditor baru ke `ReconciliationManager.php` (11 pilar).
- [x] **Phase 3: Multi-Entity Comprehensive Test Suite**
  - [x] 3.1 Buat test suite `tests/Unit/ExpandedUniversalInvariantsTest.php`.
  - [x] 3.2 Uji skenario Entitas Profit dan Entitas Non-Profit.
- [x] **Phase 4: Full Verification & Commit**
  - [x] 4.1 Eksekusi full test suite PHPUnit (534 tests) hingga 100% PASS.
  - [x] 4.2 Verifikasi `npm run typecheck` clean.
  - [x] 4.3 Git conventional commit dan pembaruan `SESSION_HANDOFF.md`.

