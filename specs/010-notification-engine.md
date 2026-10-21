# SPEC-010: Notification Engine & In-App Alerts

- **Status**: `Completed`
- **Owner / Author**: aio Framework Architecture Team
- **Tanggal Dibuat**: 2026-10-21
- **Target File / Modul**: `core/Notification/*`, `database/migrations/*`, `app/Modules/Notification/*`, `resources/js/Layouts/AppLayout.tsx`, `resources/js/Pages/Notifications/Index.tsx`, `tests/Unit/NotificationTest.php`

---

## 1. Problem Statement & Context
Dalam aplikasi web modular SaaS enterprise, pengguna memerlukan umpan balik langsung (*immediate feedback*) dan pemberitahuan kontekstual (*in-app notifications*) atas kejadian penting di dalam workspace, seperti:
1. Tindakan kritis sistem dan keamanan (pembuatan/pencabutan API token, promosi peran anggota).
2. Kolaborasi tim (proyek baru dibuat, tugas diselesaikan oleh rekan kerja).
3. Peringatan status sistem (modul dinonaktifkan secara global oleh superadmin).

Tanpa sistem notifikasi terpusat, pengguna tidak mengetahui perubahan penting yang terjadi di organisasi mereka secara real-time di UI, dan pengembang kesulitan mengirimkan notifikasi internal terstruktur.

---

## 2. Goals & Non-Goals

### Goals
- [x] Menyediakan tabel `notifications` multi-tenant dan per-pengguna dengan dukungan tipe (`info`, `success`, `warning`, `danger`), pesan, link aksi (`action_url`), ikon, dan status baca (`read_at`).
- [x] Membangun service `Core\Notification\NotificationService` yang mendukung pengiriman individual (`send`), pengiriman siaran ke seluruh anggota workspace (`broadcastToTenant`), penghitungan pesan belum dibaca (`getUnreadCount`), serta penandaan telah dibaca (`markAsRead`, `markAllAsRead`).
- [x] Membagikan status notifikasi (`unreadNotificationsCount` & recent notifications) secara otomatis via Inertia shared props di `public/index.php`.
- [x] Menambahkan Bell Icon interaktif dengan badge counter dan dropdown popover notifikasi di header [`AppLayout.tsx`](file:///c:/aplikasi/aio/resources/js/Layouts/AppLayout.tsx).
- [x] Menyediakan halaman pusat notifikasi lengkap di `GET /notifications` ([`resources/js/Pages/Notifications/Index.tsx`](file:///c:/aplikasi/aio/resources/js/Pages/Notifications/Index.tsx)).
- [x] Menyediakan endpoint API/Web untuk manajemen notifikasi (`POST /notifications/{id}/read`, `POST /notifications/read-all`, `POST /notifications/{id}/delete`).
- [x] Mengintegrasikan pemicu notifikasi otomatis saat proyek dibuat atau API token dimanipulasi.
- [x] Menyediakan perintah CLI `php aio notification:send` dan `php aio notification:list`.
- [x] Menulis unit test lengkap di `tests/Unit/NotificationTest.php`.

### Non-Goals
- Tidak mencakup pengiriman email SMTP eksternal atau Push Notification Web / Firebase pada fase ini; fokus adalah In-App Notification Center terpusat dan toast alert.

---

## 3. Technical Architecture & Design

### Alur Notifikasi
```
[Triggering Event] (e.g. Project Created, Security Alert)
           │
           ▼
[Core\Notification\NotificationService]
           │
           ├─► Simpan ke tabel `notifications` (SQLite Multi-Tenant)
           │
           ▼
[Inertia Shared Props / HTTP Endpoints]
           │
           ▼
[React 19 AppLayout Header]
   ├── 🔔 Bell Icon dengan Unread Badge Counter
   ├── 📋 Dropdown Notifikasi Terbaru & Mark as Read
   └── 📄 Halaman Pusat Notifikasi (/notifications)
```

### Database Schema (`notifications`)
```sql
CREATE TABLE IF NOT EXISTS notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    type TEXT NOT NULL DEFAULT 'info',
    title TEXT NOT NULL,
    message TEXT NOT NULL,
    action_url TEXT NULL,
    icon TEXT NULL,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_notifications_tenant_user ON notifications(tenant_id, user_id, read_at);
CREATE INDEX IF NOT EXISTS idx_notifications_created ON notifications(created_at DESC);
```

---

## 4. Atomic Task Checklist

- [x] **Phase 1: Database Migration & Core Notification Service**
  - [x] 1.1 Buat migration `database/migrations/2026_10_21_000007_create_notifications_table.sql`.
  - [x] 1.2 Jalankan migrasi via `php aio migrate`.
  - [x] 1.3 Buat service `Core\Notification\NotificationService` (send, broadcast, getUnreadCount, markAsRead, markAllAsRead, delete).
  - [x] 1.4 Daftarkan `NotificationService` ke Container di `public/index.php` dan `aio`.
  - [x] 1.5 Tulis unit tests di `tests/Unit/NotificationTest.php`.

- [x] **Phase 2: Controller & Inertia Shared Props Integration**
  - [x] 2.1 Bagikan `unreadNotificationsCount` dan `recentNotifications` di `public/index.php` via `Inertia::share`.
  - [x] 2.2 Buat controller `App\Modules\Notification\Controllers\NotificationController` (`GET /notifications`, `POST /notifications/{id}/read`, `POST /notifications/read-all`, `POST /notifications/{id}/delete`).
  - [x] 2.3 Daftarkan rute notifikasi di `routes/web.php`.
  - [x] 2.4 Uji interaksi endpoint controller via PHPUnit.

- [x] **Phase 3: Frontend UI (Bell Icon Dropdown & Pusat Notifikasi)**
  - [x] 3.1 Perbarui [`resources/js/Layouts/AppLayout.tsx`](file:///c:/aplikasi/aio/resources/js/Layouts/AppLayout.tsx) dengan Bell Icon, animated unread badge, dan popover dropdown notifikasi interaktif.
  - [x] 3.2 Buat halaman React 19 [`resources/js/Pages/Notifications/Index.tsx`](file:///c:/aplikasi/aio/resources/js/Pages/Notifications/Index.tsx) dengan filter tab (Semua, Belum Dibaca), aksi batch, dan pagination/tampilan daftar bersih.
  - [x] 3.3 Kaitkan event pembuatan proyek & manipulasi API token agar otomatis mengirim notifikasi in-app.

- [x] **Phase 4: CLI Console Tooling & Final Verification Gate**
  - [x] 4.1 Buat perintah CLI `NotificationSendCommand` dan `NotificationListCommand` di `core/Console/Commands/`.
  - [x] 4.2 Daftarkan perintah ke kernel `aio` dan buat unit test di `tests/Unit/ConsoleTest.php`.
  - [x] 4.3 Jalankan seluruh test suite (`vendor/bin/phpunit`) dan verifikasi build frontend (`npm run build`).
  - [x] 4.4 Jalankan `./scripts/verify.ps1`.
  - [x] 4.5 Perbarui dokumen spec dan handoff, buat atomic commit, lalu merge ke `main`.
