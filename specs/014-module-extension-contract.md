# SPEC-014: Module Extension Contract

- **Status**: `Completed`
- **Owner / Author**: Antigravity & User
- **Tanggal Dibuat**: 2026-10-26
- **Target File / Modul**: `core/Modules/`, `core/Auth/`, `app/Modules/`, `resources/js/`

---

## 1. Problem Statement & Context
Agar repositori ini dapat menjadi template universal untuk bisnis profit (toko, klinik, distributor) maupun non-profit (yayasan, sekolah, komunitas), modul bisnis harus bersifat benar-benar *plug-and-play* tanpa menyentuh file core:
1. Modul saat ini tidak memiliki *Service Provider* untuk mendaftarkan event listeners, custom bindings, middleware, atau CLI command secara otonom. Akibatnya, integrasi domain event memerlukan modifikasi file bootstrap.
2. Migrasi skema database terpusat di root `database/migrations/`, sehingga modul tidak membawa migrasinya sendiri. Modul yang dihapus meninggalkan sampah skema.
3. Sistem RBAC saat ini mengunci peran pada string statis (`owner`, `admin`, `member`), dan kontrol akses sering kali di-hardcode di controller. Entitas non-profit membutuhkan peran seperti `Bendahara`, `Relawan`, `Donatur`, sedangkan klinik membutuhkan `Dokter`, `Perawat`, `Kasir`.
4. Navigasi dan icon di UI frontend masih terbatas pada daftar icon statis yang di-hardcode di `AppLayout.tsx`.

## 2. Goals & Non-Goals
### Goals
- Mengimplementasikan `ModuleProvider` (`register()`, `boot()`) di setiap modul untuk registrasi listener, routes, commands, dan shared props.
- Memindahkan auto-discovery rute dan migrasi ke dalam masing-masing folder modul (`app/Modules/{Module}/Database/Migrations/`).
- Mendukung migrasi rollback per-modul (`php aio migrate:rollback --module=`).
- Mengembangkan sistem **Granular Permission Registry** (RBAC v2): modul mendeklarasikan izin (`permissions`) di `module.json`, dan tenant dapat mengonfigurasi role custom secara fleksibel.
- Mengimplementasikan `Gate::allows()` / helper `can()` dan middleware `can:permission_key`.
- Mendukung icon universal dinamis di sidebar dan navigasi bertingkat / grouping kategori di frontend.
- Menyediakan *Fixture Module* di test suite untuk memvalidasi zero-core-modification contract.

### Non-Goals
- Tidak mengimplementasikan migrasi otomatis lintas database server (PostgreSQL/MySQL).

---

## 3. Technical Architecture & Design

### Module Extension Flow
```
[Application Boot]
       │
       ├── Scan active modules for tenant
       │       └── For each module:
       │               ├── Instantiate ModuleProvider
       │               ├── $provider->register() (Bindings, Listeners, Commands)
       │               └── $provider->boot() (Routes, Shared UI Props)
       │
       └── Auto-Discover Migrations: app/Modules/*/Database/Migrations/*.sql
```

### Granular RBAC Schema
```sql
CREATE TABLE IF NOT EXISTS roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    slug TEXT NOT NULL,
    description TEXT,
    is_system INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(tenant_id, slug)
);

CREATE TABLE IF NOT EXISTS role_permissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    role_id INTEGER NOT NULL,
    permission_key TEXT NOT NULL, -- e.g. 'crm.deals.create', 'donations.verify'
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(tenant_id, role_id, permission_key)
);
```

---

## 4. Atomic Task Checklist

- [x] **Phase 1: Module Provider Contract**
  - [x] 1.1 Buat kontrak interface `Core\Modules\ModuleProviderInterface` dan abstract class `Core\Modules\ModuleProvider`.
  - [x] 1.2 Perbarui `module.json` schema agar mendukung deklarasi `"provider": "App\\Modules\\{Module}\\{Module}ServiceProvider"`.
  - [x] 1.3 Perbarui `ModuleManager` dan `Application` agar menjalankan lifecycle provider (`register()` dan `boot()`) hanya untuk modul yang aktif.
  - [x] 1.4 Sediakan API helper di provider: `listen()`, `bind()`, `command()`, `shareInertia()`.

- [x] **Phase 2: Modular Migrations & Seeders**
  - [x] 2.1 Perbarui `Core\Database\Migrator` untuk mendukung scanning direktori modul `app/Modules/*/Database/Migrations/*.sql`.
  - [x] 2.2 Tambahkan metadata kolom `module` pada tabel database `migrations`.
  - [x] 2.3 Implementasikan parser file migrasi dua arah (`-- @up` dan `-- @down`) untuk rollback.
  - [x] 2.4 Perbarui `php aio migrate` dengan opsi `--module=` dan buat perintah `php aio migrate:rollback`.
  - [x] 2.5 Dukung seed data per-modul (`php aio db:seed --module=`).

- [x] **Phase 3: Permission Registry & Dynamic RBAC (RBAC v2)**
  - [x] 3.1 Buat migrasi tabel `roles` dan `role_permissions` terisolasi tenancy dengan data default migration (`owner`, `admin`, `member`).
  - [x] 3.2 Dukung deklarasi `permissions` pada manifest `module.json`.
  - [x] 3.3 Implementasikan service `Core\Auth\Gate` dan helper `can(string $permission): bool`.
  - [x] 3.4 Buat middleware `Core\Middleware\AuthorizePermission` (`can:permission_key`).
  - [x] 3.5 Refactor pengecekan `$role === 'owner'` di controller bawaan menjadi berbasis Gate permission.
  - [x] 3.6 Bagikan permissions aktif pengguna ke Inertia props dan buat custom React hook `useCan(permission)`.

- [x] **Phase 4: Dynamic Frontend Navigation & Co-located Pages**
  - [x] 4.1 Buat dynamic Lucide icon resolver di frontend dengan fallback visual yang elegan.
  - [x] 4.2 Dukung grouping navigasi berdasarkan kategori taksonomi dan sub-menu di `AppLayout.tsx`.
  - [x] 4.3 Konfigurasi Inertia resolver agar mendukung co-located pages di `app/Modules/*/Pages/*.tsx`.

- [x] **Phase 5: Fixture Contract Test & CLI Generator Update**
  - [x] 5.1 Buat modul uji coba dummy di `tests/Fixtures/Modules/SampleContractModule` yang mengimplementasikan provider, listener, migrasi, dan permission.
  - [x] 5.2 Tulis contract integration test: verifikasi seluruh fungsionalitas modul berjalan tanpa mengedit baris kode pun di `core/`.
  - [x] 5.3 Perbarui perintah `php aio make:module` agar otomatis men-generate struktur modular baru yang patuh kontrak.
