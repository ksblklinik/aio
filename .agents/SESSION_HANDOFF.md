# Session Handoff State

Dokumen ini adalah *Single Source of Truth* untuk transfer konteks antar-sesi AI Coding di repositori **aio**. Dokumen ini dibaca secara otomatis setiap mengawali percakapan baru dan diperbarui setiap mengakhiri sesi.

---

## 📌 Status Terakhir Sesi (Last Updated: 2026-10-26 13:48 WIB)

* **Conversation ID Terakhir**: `3a77ac8e-b820-4cd1-9f4a-02f73f4aff87`
* **Active Git Branch**: `feat/015-universal-platform-services`
* **Status Dokumen / Roadmap**:
  - SPEC-013 ([`specs/013-core-hardening-and-unified-kernel.md`](file:///c:/aplikasi/aio/specs/013-core-hardening-and-unified-kernel.md)): `Completed` (merged to `main`).
  - SPEC-014 ([`specs/014-module-extension-contract.md`](file:///c:/aplikasi/aio/specs/014-module-extension-contract.md)): `Completed` (merged to `main`).
  - SPEC-015 ([`specs/015-universal-platform-services.md`](file:///c:/aplikasi/aio/specs/015-universal-platform-services.md)): `In Progress` (Phase 1 & Phase 2 completed).
  - SPEC-016 ([`specs/016-template-packaging-and-release.md`](file:///c:/aplikasi/aio/specs/016-template-packaging-and-release.md)): `Draft`.
* **Test Suite Status**: **205 tests (1111 assertions) PASS 100%**, `tsc --noEmit` clean, `verify.ps1` all passed.
* **Database File**: `database.sqlite` di root repositori.

---

## 🛡️ Catatan Mitigasi Masalah Layar Blank & Cursor Table Lock (Post-Mortem Guardrails):
- Guard otomatis di `public/index.php` untuk melayani file statis (`cli-server`).
- Unit test `ServerEnvironmentTest.php` untuk mencegah regresi bypass file statis.
- Skrip `./scripts/verify.ps1` memeriksa integritas bundle assets (`manifest.json`) dan proteksi server.
- Driver SQLite Connection otomatis memanggil `closeCursor()` pada `selectAll()` dan `selectOne()` untuk mencegah `database table is locked` saat migrasi rollback / drop table.

---

## 🚀 Rencana Eksekusi Berurutan (Sequential Roadmap)

### 1. SPEC-013: Core Hardening & Unified Kernel
- Status: `Completed` (commit `648c470`).

### 2. SPEC-014: Module Extension Contract & Dynamic RBAC
- Status: `Completed` (commit `4479a8c` & merge to `main`).

### 3. SPEC-015: Universal Platform Services
- Status: `In Progress` di branch `feat/015-universal-platform-services`.
- **Phase 1: Tenant-Isolated File Storage** (Done - commit `d9dc20b`).
  - `StorageDriverInterface`, `LocalStorageDriver`, migrasi `files`, `StorageManager` terisolasi `storage/app/tenants/{id}/`, signed URL dengan TTL HMAC SHA-256, `FileDownloadController`, validator file rules.
- **Phase 2: Tenant Settings & Multi-Domain Organization Profile** (Done - commit `565e3f3`).
  - Kolom profil `tenants` (`org_type`, `timezone`, `currency`, `locale`), migrasi `tenant_settings` (key-value dengan tipe data), service `TenantSettings` dengan in-memory cache per-request, helpers global `setting()`, `format_currency()` / `money()`, `format_date_local()`, controller `OrganizationSettingsController`, dan halaman React `Settings/Organization.tsx`.
- **Phase 3: Atomic Document Sequence Generator** (Next).
  - Migrasi `sequences`, service `SequenceManager`, format pola nomor (`{PREFIX}/{YEAR}/{NUMBER}`), locking atomik SQLite anti-tabrakan.
- **Phase 4: Scheduled Task Runner** (`php aio schedule:run`).
- **Phase 5: Mailer & Account Lifecycle Workflows** (`symfony/mailer`, `SendMailJob`, invitation, password reset).
- **Phase 6: Universal Pagination** (`Paginator` & React `<Pagination>`).

### 4. SPEC-016: Template Packaging & Release
- Buat branch: `feat/016-template-packaging`

---

## 🎯 Instruksi Untuk Langkah Segera (Immediate Next Step)
1. Lanjutkan eksekusi **Phase 3: Atomic Document Sequence Generator** di [`specs/015-universal-platform-services.md`](file:///c:/aplikasi/aio/specs/015-universal-platform-services.md).
2. Buat migrasi tabel `sequences` (`2026_10_26_000013_create_sequences_table.sql`).
3. Implementasikan `Core\Sequence\SequenceManager` dengan auto-padding dan atomic concurrency locking.
