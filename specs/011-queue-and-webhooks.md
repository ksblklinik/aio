# SPEC-011: Unified Database Queue & Outbound Webhook System

- **Status**: `Completed`
- **Owner / Author**: aio Framework Architecture Team
- **Tanggal Dibuat**: 2026-10-21
- **Target File / Modul**: `core/Queue/*`, `core/Webhook/*`, `database/migrations/*`, `app/Modules/Settings/Controllers/WebhookController.php`, `resources/js/Pages/Settings/Webhooks.tsx`, `tests/Unit/QueueTest.php`, `tests/Unit/WebhookTest.php`

---

## 1. Problem Statement & Context
Dalam arsitektur SaaS modular modern, banyak pekerjaan yang membutuhkan waktu eksekusi relatif lama atau bergantung pada ketersediaan pihak ketiga di internet:
1. **Pengiriman Webhook**: Memberi tahu endpoint eksternal (Zapier, server klien, otomasi) atas event internal (e.g. `project.created`, `task.completed`, `auth.login`). Jika dikirim secara synchronous di siklus request HTTP, kegagalan jaringan atau server eksternal yang lambat akan memblokir user dan memperlambat respons aplikasi.
2. **Heavy Background Processing**: Pengiriman notifikasi massal, kalkulasi data, atau ekspor laporan.
3. **Dead-Letter & Resilience**: Ketika pihak ketiga mengalami downtime, sistem membutuhkan mekanisme antrean (*queue*), percobaan ulang otomatis (*auto-retry with exponential backoff*), dan pencatatan kegagalan (*failed jobs log*).

---

## 2. Goals & Non-Goals

### Goals
- [x] Menyediakan tabel basis data `jobs`, `failed_jobs`, `webhooks`, dan `webhook_deliveries` dengan dukungan multi-tenant di SQLite.
- [x] Membangun engine `Core\Queue\QueueManager` dan kontrak `Core\Queue\JobInterface` dengan dukungan `push`, `pop`, `release` (delay), `delete`, `fail`, dan `retry`.
- [x] Membangun CLI Queue Worker:
  - `php aio queue:work [--queue=default] [--once] [--tries=3] [--sleep=2]`
  - `php aio queue:failed`
  - `php aio queue:retry <failed_job_id>`
- [x] Membangun `Core\Webhook\WebhookDispatcher` dan `Core\Webhook\Jobs\SendWebhookJob`:
  - Enqueue pengiriman webhook secara asynchronous ke queue.
  - Penandatanganan payload aman berbasis HMAC-SHA256 (`X-Aio-Signature` dan `X-Aio-Timestamp`).
  - Pencatatan log status pengiriman (`webhook_deliveries`) lengkap dengan HTTP response code dan durasi latensi.
- [x] Menyediakan UI Dashboard Webhook di Web (`resources/js/Pages/Settings/Webhooks.tsx`):
  - Form pendaftaran Webhook URL dengan generator secret HMAC instan.
  - Pilihan subscription event (`project.created`, `project.updated`, `auth.login`, `api_token.created`, dll).
  - Tabel riwayat pengiriman (*delivery logs*) beserta modal inspeksi payload dan status HTTP.
  - Tombol uji kirim (*Send Test Ping*).
- [x] CLI Webhook Commands: `php aio webhook:list` dan `php aio webhook:test <webhook_id>`.
- [x] Menulis unit test komprehensif di `tests/Unit/QueueTest.php` dan `tests/Unit/WebhookTest.php`.

### Non-Goals
- Tidak membutuhkan external daemon Redis/RabbitMQ di level bawaan; framework menggunakan driver basis data SQLite transaksional agar zero-dependency, mudah di-deploy di mana saja, dan offline-first.

---

## 3. Technical Architecture & Design

### Alur Asynchronous Webhook & Queue
```
[Application Event] (e.g. Project Created)
        │
        ▼
[Core\Webhook\WebhookDispatcher] ──► Query `webhooks` aktif untuk event terkait
        │
        ▼
[Core\Queue\QueueManager::push(SendWebhookJob)] ──► Simpan ke tabel `jobs`
        │                                          (Request HTTP pengguna langsung selesai!)
        ▼
[Background Worker: `php aio queue:work`]
        │
        ├─► Ambil job dari `jobs` (Atomic pop & reserve)
        ├─► Eksekusi `SendWebhookJob`
        │     ├── Generate signature HMAC-SHA256
        │     ├── Kirim HTTP POST ke endpoint eksternal
        │     └── Rekam respons ke `webhook_deliveries`
        │
        ├─► Jika Sukses ──► `delete(job)`
        └─► Jika Gagal & Melebihi Batas Tries ──► Masuk ke `failed_jobs`
```

### Database Schema
```sql
CREATE TABLE IF NOT EXISTS jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NULL,
    queue TEXT NOT NULL DEFAULT 'default',
    payload TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    reserved_at TIMESTAMP NULL,
    available_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_jobs_queue_reserved ON jobs(queue, reserved_at, available_at);

CREATE TABLE IF NOT EXISTS failed_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NULL,
    queue TEXT NOT NULL,
    payload TEXT NOT NULL,
    exception TEXT NOT NULL,
    failed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS webhooks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    url TEXT NOT NULL,
    secret TEXT NOT NULL,
    events TEXT NOT NULL DEFAULT '["*"]',
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    webhook_id INTEGER NOT NULL,
    event TEXT NOT NULL,
    payload TEXT NOT NULL,
    response_code INTEGER NULL,
    response_body TEXT NULL,
    duration_ms INTEGER NULL,
    status TEXT NOT NULL DEFAULT 'pending', -- 'success', 'failed', 'pending'
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE
);
```

---

## 4. Atomic Task Checklist

- [x] **Phase 1: Database Migration & Core Queue Engine**
  - [x] 1.1 Buat migration `database/migrations/2026_10_21_000008_create_queue_and_webhooks_tables.sql`.
  - [x] 1.2 Jalankan migrasi via `php aio migrate`.
  - [x] 1.3 Buat kontrak `Core\Queue\JobInterface` dan representasi `Core\Queue\Job`.
  - [x] 1.4 Buat engine `Core\Queue\QueueManager` (push, pop, release, delete, fail, retry).
  - [x] 1.5 Daftarkan `QueueManager` ke Container di `public/index.php` dan `aio`.
  - [x] 1.6 Tulis unit test antrean di `tests/Unit/QueueTest.php`.

- [x] **Phase 2: Outbound Webhook Engine & Signature Security**
  - [x] 2.1 Buat service `Core\Webhook\WebhookDispatcher` (pendaftaran, pencocokan event, kalkulasi HMAC-SHA256 signature).
  - [x] 2.2 Buat background job `Core\Webhook\Jobs\SendWebhookJob` dengan HTTP client / curl executor.
  - [x] 2.3 Daftarkan `WebhookDispatcher` ke Container di `public/index.php` dan `aio`.
  - [x] 2.4 Tulis unit test webhook di `tests/Unit/WebhookTest.php`.

- [x] **Phase 3: CLI Console Commands & Worker Daemon**
  - [x] 3.1 Buat CLI commands `QueueWorkCommand`, `QueueFailedCommand`, `QueueRetryCommand`.
  - [x] 3.2 Buat CLI commands `WebhookListCommand`, `WebhookTestCommand`.
  - [x] 3.3 Daftarkan perintah ke kernel `aio` dan buat unit test di `tests/Unit/ConsoleTest.php`.

- [x] **Phase 4: Web UI Dashboard (Settings > Webhooks)**
  - [x] 4.1 Buat controller `App\Modules\Settings\Controllers\WebhookController` (`GET /settings/webhooks`, `POST /settings/webhooks`, `POST /settings/webhooks/{id}/toggle`, `POST /settings/webhooks/{id}/delete`, `POST /settings/webhooks/{id}/test`).
  - [x] 4.2 Buat halaman React 19 `resources/js/Pages/Settings/Webhooks.tsx` dengan form modal pendaftaran, riwayat deliveries, dan test ping.
  - [x] 4.3 Tambahkan navigasi "Webhooks" di sidebar `AppLayout.tsx`.
  - [x] 4.4 Integrasikan pemicu webhook otomatis pada event pembuatan proyek dan API token.

- [x] **Phase 5: Self-Verification, Full Test Suite & Git Discipline**
  - [x] 5.1 Jalankan `vendor/bin/phpunit` dan `npm run build`.
  - [x] 5.2 Jalankan `./scripts/verify.ps1`.
  - [x] 5.3 Perbarui status spec di `specs/README.md` dan `.agents/SESSION_HANDOFF.md`.
  - [x] 5.4 Atomic Git commit dan merge ke `main`.
