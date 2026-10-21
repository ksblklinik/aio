# SPEC-002: Modular Auth, Multi-Tenant Workspaces, RBAC, and Project Tracker

- **Status**: `In Progress`
- **Owner / Author**: Antigravity & User
- **Tanggal Dibuat**: 2026-10-21
- **Target File / Modul**: `app/Modules/Auth/`, `app/Modules/Workspaces/`, `app/Modules/Projects/`, `resources/js/`

---

## 1. Problem Statement & Context
Pada [SPEC-001](file:///c:/aplikasi/aio/specs/001-aio-framework-core.md), kita telah membangun fondasi HTTP kernel, Inertia bridge, dan database SQLite scoping. Namun, sistem belum memiliki alur autentikasi user riil, belum memiliki relasi multi-workspace (tenant) multi-user dengan hak akses (RBAC), serta belum memiliki modul bisnis nyata untuk memvalidasi isolasi tenant.

Selain itu, dalam proses pengembangan aplikasi antarmuka visual (React), pengembang sering terhambat oleh keharusan mengisi form login berulang kali setiap kali sesi kedaluwarsa atau cache ter-refresh. Di sisi lain, menonaktifkan auth sepenuhnya akan menghilangkan visibilitas pengujian visual untuk perbedaan tampilan hak akses (role *owner* vs *member*) dan isolasi data antar-tenant.

**SPEC-002** memperkenalkan:
1. **Modular Feature Architecture**: Pengorganisasian kode di `app/Modules/` per domain bisnis.
2. **User-First Multi-Tenancy & RBAC**: Satu akun pengguna dapat memiliki atau menjadi anggota di beberapa Workspace/Tenant dengan peran `owner`, `admin`, atau `member`.
3. **Dev Persona & Fast Switcher**: Kemudahan pengujian visual lokal via `DEV_AUTO_LOGIN` dan floating toolbar 1-klik untuk beralih role/tenant tanpa ketik kredensial.
4. **Modul Bisnis Starter (Projects & Tasks)**: CRUD Project dan Task yang terikat secara otomatis ke tenant aktif untuk pembuktian isolasi data SaaS.

---

## 2. Goals & Non-Goals

### Goals
- [ ] Menerapkan struktur folder modular berbasis fitur di `app/Modules/{Auth,Workspaces,Projects}/`.
- [ ] Menyediakan skema database lengkap: `users`, `tenants`, `tenant_user` (pivot + role), `projects`, dan `tasks`.
- [ ] Menyediakan alur Autentikasi lengkap: Register (membuat user + workspace owner), Login, Logout dengan proteksi secure session & CSRF.
- [ ] Menyediakan sistem RBAC sederhana (`owner`, `admin`, `member`) dengan middleware dan helper cek peran.
- [ ] Menyediakan **Dev Persona Switcher** & **Dev Auto-Login** (hanya aktif pada `APP_ENV=local`) untuk visual testing instan tanpa login manual.
- [ ] Menyediakan modul bisnis **Project & Task Tracker** (CRUD Project & Task dengan auto-tenancy scoping).
- [ ] Menyediakan halaman antarmuka React 19 Inertia:
  - Form Login & Register modern.
  - Workspace Switcher di navbar/sidebar.
  - Halaman Projects & Task Board dengan kontrol UI berbasis role (tombol delete/invite disembunyikan untuk member).
  - Floating `<DevPersonaBar />` di pojok bawah saat dev.
- [ ] Menulis unit test PHPUnit komprehensif untuk Auth, RBAC, dan Project scoping.

### Non-Goals
- Tidak mencakup reset password via email/SMTP di iterasi 002 (fokus pada auth session lokal).
- Tidak mencakup OAuth/Social Login (Google/GitHub) pada tahap ini.
- Tidak mencakup sistem billing / pembayaran langganan (akan menjadi SPEC tersendiri).

---

## 3. Technical Architecture & Design

### Struktur Direktori Modular (`app/Modules/`)
```
app/
├── Modules/
│   ├── Auth/
│   │   ├── Controllers/
│   │   │   ├── LoginController.php
│   │   │   ├── RegisterController.php
│   │   │   └── DevPersonaController.php       # Khusus APP_ENV=local
│   │   ├── Middleware/
│   │   │   ├── Authenticate.php
│   │   │   └── RedirectIfAuthenticated.php
│   │   └── Services/
│   │       └── AuthService.php
│   │
│   ├── Workspaces/
│   │   ├── Controllers/
│   │   │   └── WorkspaceController.php        # Switch workspace, list members
│   │   ├── Middleware/
│   │   │   └── EnsureRole.php                 # Proteksi RBAC per role
│   │   └── Models/
│   │       ├── Tenant.php
│   │       └── User.php
│   │
│   └── Projects/
│       ├── Controllers/
│       │   ├── ProjectController.php
│       │   └── TaskController.php
│       └── Models/
│           ├── Project.php
│           └── Task.php
```

### Skema Basis Data (SQLite)
```sql
-- Users global
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Tenants / Workspaces
CREATE TABLE tenants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    slug TEXT UNIQUE NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Pivot Keanggotaan & Role
CREATE TABLE tenant_user (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    role TEXT NOT NULL DEFAULT 'member', -- 'owner', 'admin', 'member'
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE(tenant_id, user_id)
);

-- Modul Bisnis: Projects
CREATE TABLE projects (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    description TEXT,
    status TEXT NOT NULL DEFAULT 'in_progress', -- 'planned', 'in_progress', 'completed'
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

-- Modul Bisnis: Tasks
CREATE TABLE tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    project_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    is_completed INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);
```

### Dev Persona & Fast Switcher Architecture
* **Konfigurasi `.env`**:
  `DEV_AUTO_LOGIN=true`
* **Mekanisme**:
  * Pada middleware `Authenticate`: Jika `session('auth_user_id')` belum ada DAN `APP_ENV=local` DAN `DEV_AUTO_LOGIN=true`, sistem otomatis melakukan login ke **User 1 (Owner - Acme Corp)**.
  * Route Dev `GET /_dev/switch-persona?role={owner|member}&tenant={1|2}`:
    Mengganti session user dan tenant seketika, lalu redirect kembali ke halaman aktif saat ini.
  * Komponen Frontend `<DevPersonaBar />`: Widget mengambang di kanan-bawah layar dengan tombol beralih Persona (Owner vs Member vs Tenant 2).

---

## 4. Edge Cases & Error Scenarios
- **Email Duplikat**: Validasi registrasi menolak email yang sudah terdaftar dengan status HTTP 422 / pesan flash error.
- **Kredensial Salah**: Pesan kesalahan aman *"Email atau kata sandi tidak cocok"* tanpa membocorkan eksistensi email.
- **Cross-Tenant Data Leak**: Akses project ID milik Tenant A oleh user dari Tenant B harus melempar `404 Not Found` atau `403 Forbidden`.
- **RBAC Violation**: Member biasa mencoba menghapus project atau mengakses endpoint admin workspace melempar `403 Forbidden`.
- **Dev Route di Production**: Route `/_dev/*` melempar `404 Not Found` mutlak jika `APP_ENV === 'production'`.

---

## 5. Test Strategy
- **Unit Tests (PHPUnit)**:
  - `AuthTest`: Registrasi user & pembuatan tenant otomatis, hash password, verifikasi kredensial login/logout.
  - `RbacTest`: Validasi hak akses role (`owner`, `admin`, `member`) via middleware `EnsureRole`.
  - `ProjectScopeTest`: Uji isolasi query data project antar-tenant berbeda.
  - `DevPersonaTest`: Uji persona switcher hanya aktif saat local environment.
- **Frontend & Integration**:
  - Validasi form login, register, dan project form dengan React state & Inertia visit.
  - Uji visual via browser: pastikan `<DevPersonaBar />` memungkinkan pergantian role instan.

---

## 6. Atomic Task Checklist

- [ ] **Phase 1: Database Migration & Schema Seeding**
  - [ ] 1.1 Buat file migrasi migrasi database untuk tabel `users`, `tenants`, `tenant_user`, `projects`, dan `tasks`.
  - [ ] 1.2 Perbarui seeder awal di `scripts/init_db.php` untuk menyediakan 2 Tenant demo (`Acme Corp` & `Starlight Labs`), 1 Owner, dan 1 Member untuk keperluan visual test.
  - [ ] 1.3 Jalankan migrasi dan verifikasi integritas skema SQLite.

- [ ] **Phase 2: Modular Architecture & Models**
  - [ ] 2.1 Buat model `App\Modules\Workspaces\Models\User` dan `Tenant`.
  - [ ] 2.2 Buat model `App\Modules\Projects\Models\Project` dan `Task`.
  - [ ] 2.3 Sambungkan model ke `Core\Database\Connection` dan `TenantScope`.

- [ ] **Phase 3: Auth Engine, RBAC, & Dev Persona Switcher**
  - [ ] 3.1 Buat `App\Modules\Auth\Services\AuthService` (register, login, hash, logout).
  - [ ] 3.2 Buat middleware `Authenticate`, `RedirectIfAuthenticated`, dan `EnsureRole`.
  - [ ] 3.3 Implementasikan fitur `DEV_AUTO_LOGIN` dan `DevPersonaController` (`/_dev/switch-persona`).
  - [ ] 3.4 Buat `LoginController` dan `RegisterController`.

- [ ] **Phase 4: Business Module (Projects & Tasks CRUD)**
  - [ ] 4.1 Buat `ProjectController` (index, store, update, destroy) dengan isolasi `tenant_id`.
  - [ ] 4.2 Buat `TaskController` (store, toggle status, destroy).
  - [ ] 4.3 Registrasikan seluruh routes di `routes/web.php`.

- [ ] **Phase 5: Frontend Inertia React 19 UI**
  - [ ] 5.1 Buat halaman `resources/js/Pages/Auth/Login.tsx` dan `Register.tsx`.
  - [ ] 5.2 Perbarui `resources/js/Layouts/AppLayout.tsx` dengan Navbar Profil User, Role Badge, dan Workspace Switcher.
  - [ ] 5.3 Buat halaman `resources/js/Pages/Projects/Index.tsx` (Project & Task Board interaktif).
  - [ ] 5.4 Buat komponen `<DevPersonaBar />` mengambang untuk beralih role secara instan saat dev.

- [ ] **Phase 6: Automated Verification & Test Suites**
  - [ ] 6.1 Tulis unit tests di `tests/Unit/AuthTest.php`, `tests/Unit/RbacTest.php`, dan `tests/Unit/ProjectTest.php`.
  - [ ] 6.2 Jalankan seluruh test suite PHPUnit & TypeCheck via `./scripts/verify.ps1`.
  - [ ] 6.3 Verifikasi aplikasi berjalan mulus di browser melalui `./scripts/dev.ps1`.
