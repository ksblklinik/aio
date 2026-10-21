# SPEC-008: Audit Logging & Activity Timeline Engine

- **Status**: `Completed`
- **Owner / Author**: aio Framework Architecture Team
- **Tanggal Dibuat**: 2026-10-21
- **Target File / Modul**: `core/Audit/*`, `database/migrations/*`, `app/Modules/Activity/*`, `resources/js/Pages/Activity/*`, `tests/Unit/AuditLoggerTest.php`

---

## 1. Problem Statement & Context
Dalam aplikasi SaaS multi-tenant atau sistem enterprise, akuntabilitas (*accountability*) dan penelusuran jejak audit (*audit trail*) adalah syarat kepatuhan keamanan yang mutlak. Saat ini, perubahan penting di sistem (seperti login pengguna, aktivasi/deaktivasi modul, pembuatan/penghapusan proyek, atau manipulasi tugas) tidak tercatat di basis data. Hal ini menyebabkan:
1. Tidak adanya visibilitas bagi pemilik workspace (*Tenant Owner*) atas aktivitas para anggotanya.
2. Tidak adanya catatan forensik jika terjadi kesalahan konfigurasi, penghapusan data, atau akses tidak sah.
3. Sulitnya melacak riwayat perubahan status operasional dalam sistem.

---

## 2. Goals & Non-Goals

### Goals
- [x] Menyediakan skema basis data `audit_logs` dengan isolasi multi-tenant yang aman (`tenant_id`, `user_id`, `user_name`, `action`, `description`, `entity_type`, `entity_id`, `properties`, `ip_address`, `user_agent`, `created_at`).
- [x] Membangun service inti `Core\Audit\AuditLogger` yang otomatis mendeteksi actor (`user`), context (`tenant`), serta metadata request (IP dan browser) tanpa boilerplate berulang.
- [x] Mengintegrasikan logging otomatis pada event kritis:
  - Autentikasi: `auth.login`, `auth.logout`, `auth.register`
  - Tata Kelola Modul: `module.toggled`, `module.scaffolded`, `module.deleted`
  - Proyek & Tugas: `project.created`, `project.updated`, `project.deleted`, `task.created`, `task.toggled`, `task.deleted`
- [x] Membangun UI Activity Log modern (`resources/js/Pages/Activity/Index.tsx`) dengan timeline feed, filter event, dan metadata modal/popover.
- [x] Menyediakan perintah CLI `php aio activity:list [--limit=20]` untuk inspeksi cepat dari terminal.
- [x] Menguji seluruh fungsi logging secara komprehensif via offline-first unit/integration test (`tests/Unit/AuditLoggerTest.php` & `tests/Unit/ActivityLogControllerTest.php`).

### Non-Goals
- Tidak mencakup pengiriman log ke layanan eksternal pihak ketiga (misal: Datadog, AWS CloudWatch, Logstash) pada fase ini.

---

## 3. Technical Architecture & Design

### Alur Pencatatan Audit Log
```
[User Action in Controller / Service]
                 │
                 ▼
        [Core\Audit\AuditLogger]
  (Capture Auth User + Tenant + IP/UA)
                 │
                 ▼
      [Tabel: audit_logs (SQLite)]
                 │
        ┌────────┴────────┐
        ▼                 ▼
[UI Activity Timeline]  [CLI: php aio activity:list]
```

### Database Schema (`audit_logs`)
```sql
CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NULL,
    user_id INTEGER NULL,
    user_name TEXT NULL,
    action TEXT NOT NULL,
    description TEXT NOT NULL,
    entity_type TEXT NULL,
    entity_id TEXT NULL,
    properties TEXT NULL, -- JSON formatted metadata
    ip_address TEXT NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_audit_logs_tenant ON audit_logs(tenant_id);
CREATE INDEX IF NOT EXISTS idx_audit_logs_action ON audit_logs(action);
CREATE INDEX IF NOT EXISTS idx_audit_logs_created_at ON audit_logs(created_at);
```

---

## 4. Atomic Task Checklist

- [x] **Phase 1: Database Migration & Core Audit Engine**
  - [x] 1.1 Buat migration file `database/migrations/2026_10_21_000005_create_audit_logs_table.sql`.
  - [x] 1.2 Buat class service `Core\Audit\AuditLogger` dengan method `log()`, `getForTenant()`, dan `getGlobal()`.
  - [x] 1.3 Registrasikan `AuditLogger` ke DI Container di `public/index.php` dan `aio`.
  - [x] 1.4 Tulis unit tests di `tests/Unit/AuditLoggerTest.php` untuk memvalidasi pencatatan dan query isolasi tenant.

- [x] **Phase 2: Event Integration di Controller / Core Services**
  - [x] 2.1 Pasang audit log di `LoginController`, `RegisterController`, dan logout.
  - [x] 2.2 Pasang audit log di `ModuleManagerController` (toggle, create, delete).
  - [x] 2.3 Pasang audit log di `ProjectController` dan `TaskController` (CRUD).

- [x] **Phase 3: CLI Command & UI Activity Feed**
  - [x] 3.1 Buat CLI command `Core\Console\Commands\ActivityListCommand` (`php aio activity:list`).
  - [x] 3.2 Buat route & controller `App\Modules\Activity\Controllers\ActivityLogController` (`GET /activity`).
  - [x] 3.3 Buat halaman frontend React 19 `resources/js/Pages/Activity/Index.tsx` dengan visual timeline, badge warna tindakan, filter, dan layout terintegrasi.
  - [x] 3.4 Tambahkan menu navigasi "Riwayat Aktivitas" di sidebar `AppLayout.tsx`.

- [x] **Phase 4: Self-Verification, Full Test Suite & Git Discipline**
  - [x] 4.1 Jalankan `vendor/bin/phpunit` dan `npm run build`.
  - [x] 4.2 Uji eksekusi langsung `php aio activity:list` dan `php aio migrate`.
  - [x] 4.3 Jalankan `./scripts/verify.ps1`.
  - [x] 4.4 Update `specs/008-audit-logging.md`, `specs/README.md`, dan `.agents/SESSION_HANDOFF.md`.
  - [x] 4.5 Atomic Git commit dan merge ke `main`.
