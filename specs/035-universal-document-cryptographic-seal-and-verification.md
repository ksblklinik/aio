# SPEC-035: Universal Document Cryptographic Seal & Verification Portal

- **Status**: `Draft`
- **Owner / Author**: Antigravity Platform Team
- **Tanggal Dibuat**: 2026-10-28
- **Target Modul**: `app/Modules/Seal/`
- **Level Arsitektur**: Level 2 (Universal Shared Business Foundation)

---

## 1. Problem Statement & Context

Dalam ekosistem enterprise multi-sektor modern—termasuk **Klinik & Rumah Sakit**, **Perpajakan & Keuangan**, **Pendidikan & Pelatihan**, **HR / Manajemen Ketenagakerjaan**, serta **Logistik & Ekspor-Impor**:
1. **Kerentanan Pemalsuan Dokumen Fisik / PDF Cetak (*Document Tampering & Forgery*)**:
   - Kwitansi pembayaran, faktur tagihan (*billing invoices*), surat keterangan sakit dokter (*sick leave certificate*), resume medis, surat rujukan, surat pengalaman kerja, atau sertifikat keahlian yang diekspor ke format PDF atau dicetak fisik sangat rentan dimodifikasi secara ilegal menggunakan software pengeditan dokumen.
2. **Kebutuhan Validasi Dokumen oleh Pihak Ketiga Tanpa Login (*Zero-Trust Public Verification*)**:
   - Instansi eksternal (misal: HRD perusahaan tempat pasien bekerja yang menerima surat izin sakit, petugas klaim asuransi BPJS/swasta yang menerima kwitansi, atau aparat audit kepabeanan) membutuhkan kanal verifikasi instan.
   - Pihak pemeriksa tidak memiliki akun pengguna di sistem internal kita; mereka harus dapat memindai QR code pada dokumen fisik menggunakan kamera smartphone dan diarahkan langsung ke halaman portal publik (`/verify/{token}`) yang mengonfirmasi keaslian dokumen secara *real-time*.
3. **Pencegahan Duplikasi & Kebocoran Infrastruktur (*Zero Infrastructure Leakage*)**:
   - Jika kemampuan segel dokumen, penandatanganan digital (*digital signature / TTE*), dan portal verifikasi publik tidak dibangun di Level 2 sebagai fondasi bersama yang universal, setiap modul bisnis vertikal (Billing, Rekam Medis, Modul Surat Menyurat, Sertifikasi) akan terpaksa membuat mekanisme tanda tangan dan tabel verifikasi lokal sendiri yang rentan tidak konsisten dan tidak aman.

Modul `Seal` hadir sebagai fondasi universal Level 2 untuk menerbitkan segel kriptografis (SHA-256 + HMAC Signature), menghasilkan token verifikasi publik, menyediakan portal verifikasi terbuka bagi publik, serta mencatat jejak audit pemindaian (*tamper-proof verification logs*).

---

## 2. Goals & Non-Goals

### Goals
- [ ] Menyediakan **Document Seal Engine** (`document_seals`):
  - Menerbitkan segel kriptografis untuk dokumen resmi lintas domain (Invoice, Kwitansi, Surat Keterangan Dokter, Resume Medis, Surat Jalan, Sertifikat).
  - Menghasilkan ringkasan data kanonikal (*canonical payload hash*) menggunakan algoritma SHA-256.
  - Menghasilkan tanda tangan digital HMAC-SHA256 yang diikat pada kunci rahasia (*tenant signature secret key*).
  - Menghasilkan token verifikasi publik acak yang aman (*cryptographically secure random token*) dan URL verifikasi publik (`https://domain.com/verify/{token}`).
- [ ] Menyediakan **Public Verification Portal** (`GET /verify/{token}`):
  - Halaman web terbuka tanpa otentikasi (dapat diakses siapa saja melalui pemindaian QR code).
  - Tampilan responsif ramah seluler (*mobile-first*) dengan status validitas visual yang tegas:
    - **Hijau (Sah & Otentik)**: Menampilkan nama institusi/klinik penerbit, nomor dokumen, tanggal penerbitan, penandatangan resmi, peruntukan, dan ringkasan hash SHA-256.
    - **Merah (Dicabut / Revoked)**: Menampilkan tanggal dan alasan pencabutan (misal: "Transaksi Dibatalkan", "Surat Dianulir").
    - **Kuning (Kedaluwarsa / Expired)**: Dokumen telah melewati batas masa berlaku (*valid_until*).
    - **Abu-abu / 404 (Tidak Sah / Palsu)**: Token tidak ditemukan dalam basis data resmi.
  - Opsi penyamaran privasi (*privacy data masking*, misal: `Ah*** Fau**`, `NIK: 3171***`) untuk melindungi data pribadi sensitif pasien/konsumen dari publik.
- [ ] Menyediakan **Verification Audit Logging** (`document_verification_logs`):
  - Mencatat setiap interaksi pemindaian QR / akses portal verifikasi (timestamp, IP address, User-Agent browser, status hasil verifikasi).
- [ ] Menyediakan **Revocation & Lifecycle Management**:
  - Fasilitas pencabutan keabsahan dokumen (*revoke*) dengan alasan resmi dari penerbit.
- [ ] Menyediakan Contract Interface `DocumentSealRegistryInterface`, Global Helper `documentSeal()`, dan REST API endpoints.
- [ ] Antarmuka manajemen Web Inertia React (`resources/js/Pages/Seal/Index.tsx`) dengan tema dark/light mode baku.

### Non-Goals
- Modul ini **TIDAK** menerbitkan sertifikat X.509 dari Root CA eksternal (misal: BSrE/Peruri) secara langsung; modul ini menyediakan engine segel kriptografis internal aplikasi dan payload adapter jika ke depannya dihubungkan ke CA eksternal.
- Modul ini **TIDAK** menyimpan file binary PDF berukuran raksasa di dalam tabel database (dokumen PDF fisik tetap di-*generate on-the-fly* oleh `Core\Document\DocumentBuilder` atau disimpan di cloud storage).

---

## 3. Technical Architecture & Design

### Alur Penerbitan & Verifikasi Segel Dokumen

```
[Modul Bisnis (Billing / EHR / Sertifikat)]
       │
       ▼ (1. sealDocument(payload))
[DocumentSealService] ──> Hitung SHA-256(canonical_payload)
       │              ──> Hitung HMAC(signature, secret)
       │              ──> Generate verification_token & QR data
       ▼
[document_seals table] (Simpan status 'active', metadata, signature)
       │
       ▼ (2. Cetak Dokumen via DocumentBuilder)
[Dokumen Fisik / PDF] ──(Diserahkan ke Pasien/Klien dengan QR Code)
       │
       ▼ (3. Pihak Ketiga / HRD / Auditor Pindai QR Code via Kamera HP)
[GET /verify/{token}]
       │
       ▼ (4. Public Verification Portal)
[Validasi Hash & Status] ──> Catat Log ke [document_verification_logs]
       │
       ▼
[Tampilan Sertifikat Verifikasi Publik (Hijau: SAH / Merah: DICABUT)]
```

### Data Model & Schema (Database Migrations)

```sql
-- 1. Tabel Utama Segel Dokumen Kriptografis
CREATE TABLE IF NOT EXISTS document_seals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    verification_token TEXT NOT NULL,       -- Token acak aman (misal: 32 hex chars / UUID)
    document_type TEXT NOT NULL,            -- 'invoice', 'receipt', 'sick_leave', 'medical_resume', 'referral', 'certificate', 'custom'
    document_number TEXT NOT NULL,          -- e.g. 'INV/2026/00142', 'SKD/2026/10/0042'
    title TEXT NOT NULL,                    -- e.g. 'Surat Keterangan Sakit Pasien', 'Kwitansi Pelunasan Kasir'
    issuer_name TEXT NOT NULL,              -- e.g. 'Klinik Medika Sejahtera'
    signer_name TEXT NOT NULL,              -- e.g. 'dr. Budi Santoso, Sp.A'
    signer_role TEXT NULL,                  -- e.g. 'Dokter Pemeriksa', 'Kepala Farmasi', 'Bendahara'
    signer_credential TEXT NULL,            -- e.g. 'SIP: 503/SIP/2024', 'NIP: 19850101...'
    subject_name TEXT NOT NULL,             -- e.g. 'Ahmad Fauzi'
    subject_identifier TEXT NULL,           -- e.g. 'NIK: 31710123456789', 'MRN: RM-00129'
    subject_masked_name TEXT NULL,          -- e.g. 'Ah*** Fa***' (untuk tampilan publik)
    payload_snapshot TEXT NOT NULL,         -- JSON representasi ringkas isi pokok dokumen
    canonical_hash TEXT NOT NULL,           -- SHA-256 dari payload_snapshot
    signature TEXT NOT NULL,                -- HMAC-SHA256 dari canonical_hash
    status TEXT NOT NULL DEFAULT 'active',  -- 'active', 'revoked', 'expired'
    revoked_at TIMESTAMP NULL,
    revocation_reason TEXT NULL,
    valid_from DATE NULL,
    valid_until DATE NULL,
    metadata TEXT NULL,                     -- JSON metadata fleksibel
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_document_seals_token ON document_seals(verification_token);
CREATE INDEX IF NOT EXISTS idx_document_seals_tenant_doc ON document_seals(tenant_id, document_type, document_number);
CREATE INDEX IF NOT EXISTS idx_document_seals_hash ON document_seals(canonical_hash);

-- 2. Jejak Audit Pemindaian & Verifikasi Publik
CREATE TABLE IF NOT EXISTS document_verification_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    document_seal_id INTEGER NOT NULL,
    verification_token TEXT NOT NULL,
    verification_result TEXT NOT NULL,     -- 'success', 'revoked', 'expired', 'tampered'
    ip_address TEXT NULL,
    user_agent TEXT NULL,
    referer TEXT NULL,
    verified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_seal_id) REFERENCES document_seals(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_doc_verify_seal ON document_verification_logs(document_seal_id);
CREATE INDEX IF NOT EXISTS idx_doc_verify_time ON document_verification_logs(verified_at);
```

### Service Contract (`DocumentSealRegistryInterface.php`)

```php
namespace App\Modules\Seal\Contracts;

interface DocumentSealRegistryInterface
{
    /**
     * Menerbitkan dan menyegel dokumen secara kriptografis.
     * Mengembalikan record segel lengkap dengan verification_token, canonical_hash, dan verification_url.
     */
    public function sealDocument(array $data): array;

    /**
     * Mengambil record segel berdasarkan ID (terisolasi per tenant).
     */
    public function getSeal(int $id): ?array;

    /**
     * Mengambil detail segel dokumen berdasarkan verification_token (akses internal tenant).
     */
    public function getSealByToken(string $token): ?array;

    /**
     * Verifikasi publik dokumen berdasarkan verification_token.
     * Dapat diakses secara terbuka tanpa autentikasi; data sensitif otomatis di-masking.
     * Mencatat jejak audit pemindaian ke document_verification_logs secara otomatis.
     */
    public function verifyPublicDocument(string $token, array $requestMeta = []): array;

    /**
     * Mencabut status keabsahan segel dokumen (Revocation).
     */
    public function revokeSeal(string $token, string $reason): array;

    /**
     * Daftar segel dokumen dengan filter (document_type, status, search, pagination).
     */
    public function listSeals(array $filters = []): array;

    /**
     * Mengambil riwayat log pemindaian/verifikasi untuk dokumen tertentu.
     */
    public function getVerificationLogs(int $sealId, int $limit = 50): array;

    /**
     * Verifikasi integritas payload data langsung terhadap canonical hash dokumen.
     */
    public function verifyPayloadIntegrity(string $token, array $payload): bool;
}
```

---

## 4. Edge Cases & Error Scenarios

- **Kasus 1: Pemindaian Dokumen Palsu atau Token Sembarangan**
  - Jika token tidak ditemukan pada tabel `document_seals`, portal publik mengembalikan tampilan responsif elegan berstatus "DOKUMEN TIDAK DITEMUKAN / TIDAK TERDAFTAR" (HTTP 404) dengan peringatan kewaspadaan pemalsuan dokumen.
- **Kasus 2: Dokumen yang Telah Dibatalkan / Dicabut (Revoked)**
  - Jika pasien membatalkan kunjungan atau transaksi dinyatakan void, pemegang dokumen lama yang memindai QR code akan melihat banner merah tegas "DOKUMEN TELAH DICABUT" beserta tanggal dan alasan resmi pencabutan.
- **Kasus 3: Masa Berlaku Telah Habis (Expired)**
  - Pada surat keterangan sakit atau sertifikat yang memiliki batas waktu `valid_until`, sistem otomatis mengevaluasi tanggal dan menampilkan status kuning "MASA BERLAKU BERAKHIR" jika tanggal saat ini melampaui `valid_until`.
- **Kasus 4: Perlindungan Privasi Data Pribadi (Privacy Masking)**
  - Nama subjek pasien/konsumen dapat disamarkan sebagian secara otomatis (misal: `Budi Santoso` menjadi `Bu** San****`) di portal publik untuk mencegah kebocoran data rahasia medis kepada pihak luar yang tidak sengaja memindai kode QR.
- **Kasus 5: Integritas Kunci Kriptografi Multi-Tenant**
  - Setiap tenant memiliki salt/secret signature unik yang mencegah pemalsuan segel dokumen antar-tenant pada database bersama (*shared database*).

---

## 5. Test Strategy

- **Unit Tests (`tests/Unit/SealModuleTest.php`)**:
  - Test penerbitan segel dokumen baru dengan kalkulasi SHA-256 dan HMAC signature.
  - Test verifikasi publik (`verifyPublicDocument`) dengan status aktif (*success*).
  - Test pencabutan segel dokumen (`revokeSeal`) dan perubahan status ke *revoked*.
  - Test evaluasi dokumen kedaluwarsa (*expired status*).
  - Test pencatatan otomatis jejak audit pada `document_verification_logs`.
  - Test validasi integritas payload data (`verifyPayloadIntegrity`).
  - Test penyamaran privasi nama subjek (*privacy data masking*).
  - Test multi-tenant data isolation.
  - Test global helper `documentSeal()`.
- **Integration Tests**:
  - Test endpoint publik `GET /verify/{token}` tanpa sesi otentikasi login.
  - Test endpoint API `POST /api/seal/documents` dan `POST /api/seal/documents/{token}/revoke`.

---

## 6. Atomic Task Checklist

- [ ] **Phase 1: Setup Modul, Arsitektur, & Database Migrations**
  - [ ] 1.1 Buat manifest modul `app/Modules/Seal/module.json`.
  - [ ] 1.2 Buat migrasi `database/migrations/2026_10_28_000030_create_document_seals_tables.sql`.
  - [ ] 1.3 Jalankan migrasi via console runner dan verifikasi skema database.
- [ ] **Phase 2: Service Contracts & Core Engine Implementation (TDD)**
  - [ ] 2.1 Buat interface `App\Modules\Seal\Contracts\DocumentSealRegistryInterface`.
  - [ ] 2.2 Buat Service Provider `App\Modules\Seal\SealServiceProvider` dan daftarkan modul.
  - [ ] 2.3 Daftarkan helper global `documentSeal()` di `core/helpers.php`.
  - [ ] 2.4 Tulis test suite `tests/Unit/SealModuleTest.php` mencakup pembuatan segel, verifikasi publik, revocation, expiry, jejak audit, dan isolasi tenant.
  - [ ] 2.5 Implementasikan `App\Modules\Seal\Services\DocumentSealService` hingga seluruh unit test lolos 100%.
- [ ] **Phase 3: Web UI, Public Verification Portal, & REST API**
  - [ ] 3.1 Buat routes modul di `app/Modules/Seal/routes.php` (mencakup rute terbuka tanpa auth `/verify/{token}`).
  - [ ] 3.2 Implementasikan Web Controller `SealController` untuk dashboard back-office manajemen segel dokumen.
  - [ ] 3.3 Implementasikan Public Controller `PublicVerifyController` untuk halaman publik verifikasi QR code.
  - [ ] 3.4 Implementasikan REST API Controller `ApiSealController` untuk integrasi segel lintas modul.
  - [ ] 3.5 Buat antarmuka pengguna responsif Inertia React `resources/js/Pages/Seal/Index.tsx` untuk back-office.
  - [ ] 3.6 Buat halaman verifikasi publik `resources/js/Pages/Seal/Verify.tsx` (mobile-first, status badge, rincian keabsahan, anti-tampering hash).
- [ ] **Phase 4: Final Verification, Review & Handoff**
  - [ ] 4.1 Jalankan seluruh test suite global via `vendor/bin/phpunit.bat`.
  - [ ] 4.2 Jalankan `npm run typecheck` dan build aset frontend.
  - [ ] 4.3 Perbarui `specs/README.md` dan `.agents/SESSION_HANDOFF.md`.
  - [ ] 4.4 Merge atomic commit ke branch `main`.
