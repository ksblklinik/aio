# SPEC-013: Core Hardening & Unified Kernel

- **Status**: `In Progress`
- **Owner / Author**: Antigravity & User
- **Tanggal Dibuat**: 2026-10-26
- **Target File / Modul**: `core/`, `public/index.php`, `aio`, `config/`

---

## 1. Problem Statement & Context
Sebelum modul bisnis dipasang atau repositori dijadikan template universal (baik untuk entitas bisnis profit maupun organisasi non-profit), fondasi Core saat ini memiliki beberapa celah teknis dan bug laten:
1. Bootstrap aplikasi terduplikasi dan divergen antara web (`public/index.php`) dan CLI (`aio`).
2. Queue worker (`QueueWorkCommand`) tidak memulihkan `TenantContext` saat mengeksekusi job asinkron, menyebabkan kegagalan jika listener mengakses `TenantScope`.
3. `TenantScope` hanya mendukung operasi `selectAll`, `selectOne`, dan `insert`, memaksa modul menulis SQL raw untuk update/delete yang rawan kebocoran data antar-tenant.
4. Metode `Connection::transaction()` hanya me-rollback pada `PDOException`, sehingga exception umum (`Throwable`) berisiko meninggalkan data setengah tersimpan atau koneksi SQLite terkunci.
5. SQLite PRAGMA esensial (`foreign_keys = ON`, `journal_mode = WAL`, `busy_timeout = 5000`) belum diaktifkan secara konsisten di setiap inisialisasi koneksi.
6. Belum ada loader environment / konfigurasi standar (`.env` parser), path database di-hardcode di berbagai file, dan terjadi doc-drift pada dokumentasi handoff (`database.sqlite` vs `database/aio.sqlite`).
7. Error handling di `public/index.php` membocorkan stack trace dan path file sensitif bahkan di lingkungan non-dev, serta belum ada logging terstruktur.
8. `Request` belum mendukung file uploads (`$_FILES`) dan HTTP method spoofing (`_method`).

## 2. Goals & Non-Goals
### Goals
- Mengimplementasikan parser konfigurasi `.env` native tanpa library eksternal dan memusatkan konfigurasi ke folder `config/`.
- Memusatkan bootstrap web dan CLI ke satu `Core\Foundation\Application` terpadu.
- Mengaktifkan PRAGMA SQLite (`foreign_keys`, `WAL`, `busy_timeout`) otomatis di `Connection`.
- Memperluas `TenantScope` agar mendukung `update`, `delete`, `count`, `exists`, dan `paginate`.
- Memastikan `transaction()` me-rollback pada semua `Throwable` dan mendukung savepoint / nested transaction.
- Memastikan background queue worker menginjeksi dan memulihkan `TenantContext` sebelum dan sesudah eksekusi job.
- Menyediakan `Core\Log\Logger` berbasis rotasi harian file log dan menyembunyikan detail exception jika `APP_DEBUG=false`.
- Mendukung `Request::file()` / `UploadedFile` dan method spoofing `_method` (PUT, PATCH, DELETE).

### Non-Goals
- Tidak mengubah kontrak modul atau dynamic provider (dikerjakan di SPEC-014).
- Tidak mengimplementasikan driver database selain SQLite.

## 3. Technical Architecture & Design

### Unified Application Bootstrapping
```
[public/index.php] ──┐
                     ├──> [Core\Foundation\Application] ──> [Container Singleton]
[aio (CLI Console)] ─┘                 │
                                       ├── Load .env & config/*.php
                                       ├── Init Database (PRAGMA WAL & FK ON)
                                       ├── Init Log, Session, TenantContext
                                       └── Register Core Services
```

### Data Model & Schema
Tidak ada perubahan skema database baru di SPEC-013. Konfigurasi path database disatukan ke `config/database.php` membaca `DB_PATH` (default: `database.sqlite`).

---

## 4. Edge Cases & Error Scenarios
- File `.env` tidak ada: Fallback aman ke default values di file `config/*.php`.
- Transaksi gagal akibat `ValidationException` atau logic error: `Connection::transaction()` harus tetap rollback tanpa meninggalkan transaksi aktif.
- Concurrency tinggi di SQLite: `busy_timeout=5000` mencegah error `database is locked` seketika.
- Worker crash di tengah job: Pastikan tenant ID di-reset ke `null` pada block `finally` agar tidak mencemari job berikutnya.

---

## 5. Test Strategy
- Unit test parser `.env` dan `config()`.
- Unit test `Connection`: verifikasi PRAGMA SQLite aktif, verifikasi rollback pada `Exception` non-PDO, dan nested transaction.
- Unit test `TenantScope`: uji `update`, `delete`, `count`, `exists`, dan validasi pencegahan lintas-tenant.
- Unit test Queue: uji job tenant membawa dan mengembalikan konteks tenant di worker.
- Unit test Logger & Error Handler: verifikasi log tertulis di `storage/logs/` dan output 500 aman saat `APP_DEBUG=false`.

---

## 6. Atomic Task Checklist

- [x] **Phase 1: Config & Environment Engine**
  - [x] 1.1 Buat unit test untuk `Core\Config\Env` dan `Core\Config\Config`.
  - [x] 1.2 Implementasi `Core\Config\Env` (parser `.env` native mandiri) dan helper `env()`.
  - [x] 1.3 Implementasi `Core\Config\Config` dan helper `config()`.
  - [x] 1.4 Buat `config/app.php` dan `config/database.php`; perbarui `.env.example`.
  - [x] 1.5 Hilangkan hardcoded path database di seluruh repositori, arahkan ke `config('database.path')`.

- [x] **Phase 2: Database Integrity & TenantScope Completeness**
  - [x] 2.1 Buat unit test untuk PRAGMA SQLite, `transaction()` rollback Throwable, dan nested savepoints.
  - [x] 2.2 Perbarui `Core\Database\Connection` untuk mengaktifkan `foreign_keys = ON`, `journal_mode = WAL`, dan `busy_timeout = 5000`.
  - [x] 2.3 Perbaiki `Connection::transaction()` agar me-rollback pada `Throwable` apa pun dan mendukung savepoints.
  - [x] 2.4 Tambahkan metode `update(string $table, array $data, string $where, array $params): int` dan `delete(...)` di `Connection`.
  - [x] 2.5 Buat unit test komprehensif untuk `Core\Database\TenantScope`.
  - [x] 2.6 Implementasikan metode `update()`, `delete()`, `count()`, `exists()`, dan `paginate()` di `TenantScope`.

- [x] **Phase 3: Unified Application Kernel**
  - [x] 3.1 Buat unit test keselarasan container web vs CLI.
  - [x] 3.2 Implementasikan `Core\Foundation\Application` sebagai single source of truth inisialisasi framework.
  - [x] 3.3 Refactor `public/index.php` dan `aio` untuk menggunakan `Core\Foundation\Application`.
  - [x] 3.4 Standarisasi inisialisasi `AuditLogger` di lingkungan CLI dan Web.

- [ ] **Phase 4: Queue Tenant Context Propagation**
  - [ ] 4.1 Buat integration test queue worker dengan job yang mengakses `TenantScope`.
  - [ ] 4.2 Perbarui `QueueWorkCommand` dan runner queue agar menyetel `TenantContext::setTenantId()` sebelum eksekusi dan mereset di blok `finally`.

- [ ] **Phase 5: Logging, Error Handling & HTTP Completeness**
  - [ ] 5.1 Buat unit test untuk `Core\Log\Logger`.
  - [ ] 5.2 Implementasi `Core\Log\Logger` dengan rotasi harian file di `storage/logs/aio-YYYY-MM-DD.log`.
  - [ ] 5.3 Buat exception handler yang menyembunyikan detail stack trace saat `APP_DEBUG=false` dan selalu mencatat error ke Logger.
  - [ ] 5.4 Buat unit test `UploadedFile` dan method spoofing `_method`.
  - [ ] 5.5 Implementasi `Core\Http\UploadedFile` dan integrasikan ke `Request::capture()` dan helper `Request::file()`.
  - [ ] 5.6 Implementasi dukungan method spoofing (`_method`) pada `Request::capture()`.

- [ ] **Phase 6: Verification & Documentation Sync**
  - [ ] 6.1 Jalankan seluruh test suite (`phpunit`) dan verifikasi `scripts/verify.ps1`.
  - [ ] 6.2 Sinkronkan dokumentasi handoff dan bersihkan anomali doc-drift path database.
