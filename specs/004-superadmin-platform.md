# SPEC-004: Platform Superadmin & Global Governance

- **Status**: `Completed`
- **Owner / Author**: aio Framework Team
- **Tanggal Dibuat**: 2026-10-21
- **Target File / Modul**: `app/Modules/Auth`, `app/Modules/Workspaces`, `core/Modules`, `resources/js`

---

## 1. Problem Statement & Context
Sebelumnya, sistem aio hanya memiliki role di tingkat workspace (`owner`, `admin`, `member`). Tidak ada entitas pengguna yang memiliki otoritas tingkat platform/sistem (*global server administrator*). Akibatnya:
1. Tidak ada pengelola yang berhak mematikan modul di tingkat server secara global (`system_modules.is_active`).
2. Pemilik server tidak bisa membedakan antara pemilik salah satu tenant (*Tenant Owner*) dengan pengelola sistem menyeluruh (*Superadmin*).

## 2. Goals & Non-Goals
### Goals
- [x] Menambahkan atribut `is_superadmin` pada tabel `users`.
- [x] Menyediakan seeder akun default Superadmin: `superadmin@aio.local` (password: `password`).
- [x] Middleware `EnsureSuperadmin` untuk melindungi rute kontrol platform global.
- [x] Menambahkan kontrol Superadmin di `ModuleManager` (`toggleGlobalActive`).
- [x] Menampilkan badge/akses khusus Superadmin di navigasi aplikasi dan persona switcher cepat dev.
- [x] Unit test komprehensif untuk validasi keamanan dan otoritas Superadmin.

### Non-Goals
- Fitur billing multi-gateway SaaS (akan diimplementasikan di spec terpisah).

## 3. Technical Architecture & Design

### Schema Migration
`database/migrations/2026_10_21_000004_add_is_superadmin_to_users_table.sql`:
```sql
ALTER TABLE users ADD COLUMN is_superadmin INTEGER NOT NULL DEFAULT 0;
```

### Seeder Superadmin
```php
$superadmin = [
    'name' => 'System Superadmin',
    'email' => 'superadmin@aio.local',
    'password_hash' => password_hash('password', PASSWORD_BCRYPT),
    'is_superadmin' => 1,
];
```

### Middleware Pipeline
```
[Request] ──> [Authenticate] ──> [EnsureSuperadmin] ──> [PlatformController / ModuleManager]
```

---

## 4. Atomic Task Checklist

- [x] **Phase 1: Database Migration, Model & Seeder**
  - [x] 1.1 Buat migrasi `database/migrations/2026_10_21_000004_add_is_superadmin_to_users_table.sql`.
  - [x] 1.2 Update model `User.php` dengan properti `$isSuperadmin` dan pemetaan database.
  - [x] 1.3 Update `scripts/init_db.php` untuk men-seed akun `superadmin@aio.local`.

- [x] **Phase 2: Auth Service, Middleware & ModuleManager Engine**
  - [x] 2.1 Tambahkan metode `isSuperadmin(): bool` di `AuthService.php`.
  - [x] 2.2 Buat middleware `EnsureSuperadmin.php`.
  - [x] 2.3 Tambahkan metode `toggleGlobalActive(string $id)` di `ModuleManager.php` khusus untuk admin sistem.
  - [x] 2.4 Daftarkan Superadmin persona di `DevPersonaController.php` untuk pengujian cepat 1-klik.

- [x] **Phase 3: Controller, Shared Props & UI Integration**
  - [x] 3.1 Bagikan status `is_superadmin` ke Inertia shared props di `public/index.php`.
  - [x] 3.2 Update `ModuleManagerController.php` untuk mendukung aksi global toggle jika user adalah superadmin.
  - [x] 3.3 Update `resources/js/Pages/Modules/Index.tsx` dan `AppLayout.tsx` untuk menampilkan badge status Superadmin.

- [x] **Phase 4: Automated Testing & Verification**
  - [x] 4.1 Tulis unit test `tests/Unit/SuperadminTest.php`.
  - [x] 4.2 Jalankan full test suite `vendor/bin/phpunit`.
  - [x] 4.3 Jalankan frontend build `npm run build`.
  - [x] 4.4 Perbarui `specs/README.md` dan `.agents/SESSION_HANDOFF.md`.
  - [x] 4.5 Atomic Git commit.
