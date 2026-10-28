# SPEC-042: Universal Tenant Crypto-Shredding, Data Portability & GDPR/PDP Anonymization Engine

- **Status**: `Completed`
- **Owner / Author**: Antigravity & Lead Architect
- **Tanggal Dibuat**: 2026-10-28
- **Target File / Modul**: `core/Privacy`, `database/migrations`, `core/Console/Commands`, `core/Foundation/Application.php`, `core/helpers.php`

---

## 1. Problem Statement & Context
Dalam arsitektur SaaS multi-tenant enterprise (terutama di sektor kesehatan, finansial, dan B2B), kepatuhan terhadap regulasi privasi data tingkat tinggi (UU No. 27/2022 Pelindungan Data Pribadi / UU PDP di Indonesia, GDPR Article 17 "Right to be Forgotten", dan HIPAA) membebankan persyaratan ketat:
1. **Hak Portabilitas Data (Right to Data Portability)**:
   Tenant memiliki hak hukum untuk mengekspor seluruh aset data mereka dalam format universal terstruktur (JSON/ZIP) secara lengkap tanpa kehilangan relasi (kontak, catatan medis, faktur, buku besar, inventaris, fasilitas, dan antrean).
2. **Hak Penghapusan & Crypto-Shredding (Right to Erasure)**:
   Menghapus baris fisik data (hard delete) pada database SQLite multi-tenant memiliki risiko tinggi:
   - Merusak integritas referensial buku besar akuntansi dan faktur historis yang wajib disimpan untuk pelaporan pajak selama kurun waktu tertentu.
   - Sisa data pada file SQLite freelist pages, Write-Ahead Logs (`-wal`), dan file backup dingin (cold storage snapshots) tetap bisa dibaca oleh pihak forensik jika data tersimpan sebagai plaintext.
3. **Solusi Kriptografis (Zero Data Leakage via Crypto-Shredding)**:
   Data PII (*Personally Identifiable Information*) dan catatan sensitif dienkripsi menggunakan kunci kriptografis unik per tenant (*per-tenant derived encryption key*). Saat tenant meminta penghapusan total atau hak untuk dilupakan, memusnahkan (*shredding*) kunci enkripsi tenant tersebut secara matematis akan melenyapkan kemampuan membaca seluruh data historis mereka di semua snapshot database masa lalu secara permanen, tanpa merusak struktur relasi tabel database.

---

## 2. Goals & Non-Goals

### Goals
- [x] **Per-Tenant Cryptographic Key Management**:
  - Pengelolaan kunci enkripsi terisolasi per tenant menggunakan per-tenant salt dan master `APP_KEY`.
  - Kemampuan enkripsi/dekripsi AES-256-GCM / AES-256-CBC untuk muatan data sensitif tenant.
  - Fitur pemusnahan kunci instan (*instant crypto-shredding*): menghancurkan record kunci dengan random bytes sebelum dihapus sehingga data tersandi menjadi sampah matematis (*unrecoverable ciphertext*).
- [x] **Universal Data Portability Exporter**:
  - Ekspor deterministik seluruh entitas tenant (`users`, `contacts`, `financial_accounts`, `financial_ledgers`, `items`, `invoices`, `facilities`, `appointments`, `audit_logs`).
  - Menghasilkan archive terstruktur berekstensi `.json` atau payload archive dengan checksum SHA-256 integrity digest.
- [x] **Cascading Anonymizer & Physical Data Shredder**:
  - Mode **Pseudonymization / Anonymization**: Menggantikan identitas personal (nama, email, no HP, alamat) dengan deterministic hash `ANON-xxxx` tanpa merusak catatan saldo keuangan.
  - Mode **Hard Shredding**: Cascading foreign-key safe deletion pada seluruh tabel berlingkup `tenant_id`.
  - Penerbitan sertifikat pemusnahan resmi bertanda tangan digital (*Cryptographic Certificate of Destruction*) via modul `seal()`.
- [x] **CLI Tooling & Global Helper**:
  - Perintah `php aio tenant:export <tenant_id>`.
  - Perintah `php aio tenant:shred <tenant_id> [--anonymize] [--force]`.
  - Perintah `php aio tenant:crypto-shred <tenant_id> [--force]`.
  - Global helper `privacy(): TenantPrivacyManager`.

### Non-Goals
- Tidak menggantikan enkripsi filesystem level OS (BitLocker/LUKS) untuk disk fisik host.
- Tidak menghapus akun superadmin global yang tidak terikat pada satu tenant spesifik.

---

## 3. Technical Architecture & Design

### Alur Arsitektur
```
[Privacy Engine (core/Privacy)]
   ├── TenantKeyManager ──────────> Enkripsi / Dekripsi / Pemusnahan Kunci Per-Tenant
   ├── DataPortabilityExporter ───> Ekspor JSON Portabel Komprehensif Seluruh Modul
   └── TenantDataShredder ────────> Pseudonimisasi PII / Hard Purge + Segel Pemusnahan (Seal)
```

### Data Model: `tenant_encryption_keys`
```sql
CREATE TABLE IF NOT EXISTS tenant_encryption_keys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL UNIQUE,
    key_salt TEXT NOT NULL,
    key_cipher TEXT NOT NULL,
    algorithm TEXT NOT NULL DEFAULT 'aes-256-cbc',
    is_shredded INTEGER NOT NULL DEFAULT 0,
    shredded_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_tenant_keys_tenant ON tenant_encryption_keys(tenant_id);
```

---

## 4. Atomic Task Checklist

- [x] **Phase 1: Database Migration & Privacy Contracts**
  - [x] 1.1 Buat migrasi `database/migrations/2026_10_28_000036_create_tenant_encryption_keys_table.sql`.
  - [x] 1.2 Jalankan migrasi via `php aio migrate`.
  - [x] 1.3 Buat interface `Core\Privacy\TenantPrivacyManagerInterface`.

- [x] **Phase 2: Privacy Subsystems Implementation**
  - [x] 2.1 Buat `Core\Privacy\TenantKeyManager` (key derivation, AES-256 encrypt/decrypt, crypto-shredding).
  - [x] 2.2 Buat `Core\Privacy\DataPortabilityExporter` (multi-module aggregator, portable JSON, SHA-256 checksum).
  - [x] 2.3 Buat `Core\Privacy\TenantDataShredder` (PII anonymization, cascading purge, seal certification).
  - [x] 2.4 Buat facade `Core\Privacy\TenantPrivacyManager`.
  - [x] 2.5 Tambahkan helper global `privacy()`.
  - [x] 2.6 Daftarkan bindings di `Core\Foundation\Application.php`.

- [x] **Phase 3: CLI Console Tooling**
  - [x] 3.1 Buat perintah `php aio tenant:export` (`core/Console/Commands/TenantExportCommand.php`).
  - [x] 3.2 Buat perintah `php aio tenant:shred` (`core/Console/Commands/TenantShredCommand.php`).
  - [x] 3.3 Buat perintah `php aio tenant:crypto-shred` (`core/Console/Commands/TenantCryptoShredCommand.php`).
  - [x] 3.4 Daftarkan perintah CLI di `Application::createCliKernel()`.

- [x] **Phase 4: Unit Testing & Self-Verification**
  - [x] 4.1 Tulis unit test komprehensif `tests/Unit/TenantCryptoShreddingTest.php` (key isolation, crypto-shredding unrecoverability, portability export integrity, PII anonymization vs hard shred, destruction certificate).
  - [x] 4.2 Jalankan PHPUnit test suite global dan pastikan 100% lolos.
  - [x] 4.3 Jalankan TypeScript typecheck (`npm run typecheck`) dan Vite build (`npm run build`).
  - [x] 4.4 Perbarui status dokumen spec ini menjadi `Completed`.
  - [x] 4.5 Perbarui `specs/README.md` dan `.agents/SESSION_HANDOFF.md`.
  - [x] 4.6 Buat atomic Git commit dan merge branch ke `main`.
