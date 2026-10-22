# SPEC-012: Core Interoperability (Domain Event Engine) & Module Sanitization

- **Status**: `In Progress`
- **Owner / Author**: aio Architecture Team & AI Agent
- **Tanggal Dibuat**: 2026-10-22
- **Target File / Modul**: `core/Events/*`, `app/Modules/*`, `core/Modules/*`, `.agents/rules/08-zero-infrastructure-leakage.md`, `AGENTS.md`

---

## 1. Problem Statement & Context

Saat ini repositori `aio` telah memiliki Database Queue (SPEC-011), Webhook Outbound, In-App Notifications (SPEC-010), dan REST API (SPEC-009). Namun, terdapat dua masalah arsitektural krusial yang menghambat skalabilitas dan interoperabilitas sistem:

1. **Inkonsistensi Modular Monolith (Sanitasi)**:
   - Dari 8 folder di dalam `app/Modules/`, hanya 4 yang berstatus modul resmi (`Projects`, `Modules`, `Workspaces`, `Auth` yang memiliki `module.json`).
   - 4 folder lainnya (`Activity`, `Api`, `Notification`, `Settings`) adalah folder *ad-hoc* tanpa manifesto `module.json`, dan rutenya masih di-hardcode di `routes/web.php`.
   - Hal ini menimbulkan kebingungan arsitektur ("setengah modul, setengah bukan").

2. **Absennya Domain Event Engine (Interoperabilitas Antar-Modul)**:
   - Belum ada mekanisme *publish/subscribe* terstandar antar-modul.
   - Jika modul bisnis baru (misal CRM) ingin memicu pengiriman webhook, audit log, atau notifikasi, saat ini modul tersebut harus memanggil `WebhookService` atau `NotificationService` secara langsung (*tight-coupling*).
   - Diperlukan **Domain Event Dispatcher** yang decoupled, dengan dukungan listener sinkron (*in-memory*) maupun asinkron (*pushed to database queue*).

3. **Risiko Kebocoran Infrastruktur (Zero Leakage)**:
   - Belum ada aturan konstitusi eksplisit yang melarang agen/kontributor membuat *workaround* infrastruktur di level modul bisnis saat menemukan fondasi core yang belum lengkap.

---

## 2. Goals & Non-Goals

### Goals
- [ ] **Sanitasi Total `app/Modules/`**: Seluruh 8 folder memiliki `module.json` standar dengan metadata valid, kategori terdaftar, dan rute modular (`routes.php`) yang dimuat secara dinamis oleh `ModuleManager`.
- [ ] **Domain Event Engine**:
  - Membuat class `Core\Events\Event` (base event) dan `Core\Events\ListenerInterface.php`.
  - Mendukung pendaftaran listener sinkron dan listener asinkron (otomatis dilempar ke `QueueManager` dari SPEC-011).
  - Menyediakan integrasi otomatis (bridge) antara Domain Events dan Outbound Webhooks (jika ada webhook yang mendengarkan nama event tersebut, otomatis disalurkan ke `WebhookService`).
  - Helper global `event(object $event): void` atau dependency injection via Container.
- [ ] **Guardrail Konstitusi "Zero Infrastructure Leakage"**:
  - Membuat aturan formal `.agents/rules/08-zero-infrastructure-leakage.md`.
  - Memperbarui `AGENTS.md` (tambahkan Guardrail 6: Zero Infrastructure Leakage).

### Non-Goals
- Tidak mengubah skema tabel database yang sudah ada (tidak ada breaking migration).
- Tidak memindahkan halaman UI frontend (`resources/js/Pages/*`) ke direktori lain agar tidak merusak Inertia resolver.

---

## 3. Technical Architecture & Design

### Alur Domain Event & Interoperabilitas Antar-Modul
```
[Business / Core Module]
        │
        ▼ dispatch(Event)
┌──────────────────────────────────────────────┐
│        Core\Events\EventDispatcher           │
└──────┬──────────────────────┬──────────────┬─┘
       │ (Sync Listeners)     │ (Async)      │ (Webhook Auto-Bridge)
       ▼                      ▼              ▼
[In-Memory Handlers]   [QueueManager]  [WebhookService]
(Audit / State Change) (Background Job) (Outbound HTTP HMAC)
```

### Format Manifesto Modul Baru untuk Sanitasi
Folder `Activity`, `Notification`, `Settings`, dan `Api` akan dilengkapi `module.json` resmi berstatus `is_core: true`:
- `Activity/module.json`: category `core`, icon `Activity`, route `/activity`
- `Notification/module.json`: category `core`, icon `Bell`, route `/notifications`
- `Settings/module.json`: category `core`, icon `Settings`, route `/settings/webhooks`
- `Api/module.json`: category `core`, icon `Key`, route `/settings/api-tokens`

Rute-rute terkait di `routes/web.php` dipindahkan ke file `routes.php` masing-masing modul.

---

## 4. Edge Cases & Error Scenarios
- **Listener Gagal (Exception)**: Listener sinkron yang melempar exception harus ditangani secara elegan atau di-log tanpa menghentikan request utama jika diatur sebagai non-blocking.
- **Queue Tidak Aktif**: Listener asinkron harus memverifikasi bahwa tabel queue tersedia; jika gagal, catat error di error log.
- **Modul Non-Aktif**: Jika modul dinonaktifkan untuk tenant tertentu, listener yang berasal dari modul tersebut tidak boleh dieksekusi.

---

## 5. Test Strategy
- **Unit Test `EventDispatcherTest`**:
  - Uji dispatch event ke listener sinkron.
  - Uji dispatch event ke listener asinkron (verifikasi job masuk ke tabel `jobs`).
  - Uji auto-bridge event ke outbound webhook payload.
- **Unit Test `ModuleSanitizationTest`**:
  - Verifikasi seluruh folder di `app/Modules/` memiliki `module.json` valid.
  - Verifikasi seluruh modul tersinkronisasi ke tabel `system_modules` tanpa error.
- **Regression Verification**:
  - Jalankan `vendor/bin/phpunit` (seluruh 148+ tes harus tetap lolos).
  - Jalankan `npm run build` dan `powershell -ExecutionPolicy Bypass -File .\scripts\verify.ps1`.

---

## 6. Atomic Task Checklist

- [x] **Phase 1: Konstitusi & Rule Zero Infrastructure Leakage**
  - [x] 1.1 Buat dokumen `.agents/rules/08-zero-infrastructure-leakage.md`.
  - [x] 1.2 Perbarui `AGENTS.md` (tambahkan Guardrail 6: Zero Infrastructure Leakage).

- [x] **Phase 2: Sanitasi Direktori `app/Modules/`**
  - [x] 2.1 Buat `app/Modules/Activity/module.json` dan `routes.php`.
  - [x] 2.2 Buat `app/Modules/Notification/module.json` dan `routes.php`.
  - [x] 2.3 Buat `app/Modules/Settings/module.json` dan `routes.php`.
  - [x] 2.4 Buat `app/Modules/Api/module.json` dan `routes.php`.
  - [x] 2.5 Bersihkan rute statis terkait dari `routes/web.php` dan pastikan dimuat via dynamic module route loader.
  - [x] 2.6 Buat unit test `tests/Unit/ModuleSanitizationTest.php` untuk memvalidasi kepatuhan manifesto semua modul.

- [x] **Phase 3: Core Domain Event Engine**
  - [x] 3.1 Buat interface & base class `Core\Events\Event.php` dan `Core\Events\ListenerInterface.php`.
  - [x] 3.2 Buat `Core\Events\EventDispatcher.php` (registrasi listener sinkron, asinkron, dan webhook bridge).
  - [x] 3.3 Buat asynchronous event job `Core\Events\QueuedEventJob.php` yang kompatibel dengan `QueueManager`.
  - [x] 3.4 Daftarkan `EventDispatcher` ke DI Container dan buat helper global `event(object $event)`.
  - [x] 3.5 Tulis unit test `tests/Unit/EventDispatcherTest.php` untuk menguji skenario sync, async, dan webhook bridge.

- [ ] **Phase 4: Integrasi & Verifikasi Akhir**
  - [ ] 4.1 Jalankan test suite PHPUnit lengkap (`vendor/bin/phpunit`).
  - [ ] 4.2 Jalankan verifikasi sistem (`./scripts/verify.ps1`).
  - [ ] 4.3 Perbarui `specs/README.md` dan `.agents/SESSION_HANDOFF.md`.
