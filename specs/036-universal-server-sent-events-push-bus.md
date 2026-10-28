# SPEC-036: Universal Server-Sent Events (SSE) Push Bus & Real-Time Broadcast

- **Status**: `Completed`
- **Owner / Author**: Antigravity & Lead Architect
- **Tanggal Dibuat**: 2026-10-28
- **Target File / Modul**: `core/Broadcasting`, `core/Broadcasting/SseController.php`, `app/Modules/Schedule`, `app/Modules/Seal`, `resources/js`

---

## 1. Problem Statement & Context
Dalam arsitektur aplikasi berbasis web interaktif (seperti TV Display Antrean pasien di klinik, notifikasi real-time di navbar, dan pemantauan verifikasi dokumen publik), pembaruan data secara langsung (*real-time update*) sangat krusial. 

Pendekatan sebelumnya menggunakan HTTP polling berkala (`setInterval` 3 detik pada antrean), yang memiliki kelemahan mendasar:
1. **Latensi Tinggi**: Terdapat jeda 0 - 3000 ms antara panggilan loket dengan pengumuman audio TV display.
2. **Beban Server Tidak Perlu**: Ratusan klien yang melakukan polling konstan membebani database dan web server meskipun tidak ada perubahan status.
3. **Ketergantungan Eksternal**: Penggunaan WebSocket konvensional (Pusher, Laravel Reverb, Soketi, Swoole) memerlukan daemon atau layanan eksternal terpisah yang menambah kompleksitas deployment bagi solo developer dan melanggar prinsip *Zero Infrastructure Leakage*.

**Solusi**: Membangun **Universal Server-Sent Events (SSE) Push Bus** murni berbasis standar HTTP (`text/event-stream`) di level Core Framework. SSE didukung secara native oleh seluruh peramban modern melalui API `EventSource`, mendukung isolasi multi-tenant, otomatis memulihkan koneksi terputus via `Last-Event-ID`, dan berjalan mulus di atas lingkungan PHP standar tanpa proses daemon terpisah.

---

## 2. Goals & Non-Goals

### Goals
- [x] Menyediakan tabel penyimpanan event `sse_events` dengan indeks teroptimasi untuk kueri inkremental cepat per tenant dan channel.
- [x] Menyediakan kontrak `Core\Broadcasting\SseBroadcasterInterface` dan implementasi `SseBroadcaster` untuk publikasi event instan.
- [x] Menyediakan endpoint HTTP `GET /api/sse/stream` yang memancarkan aliran `text/event-stream`, heartbeat ping, dan pemulihan `Last-Event-ID`.
- [x] Menyediakan endpoint fallback `GET /api/sse/poll` untuk lingkungan jaringan yang memblokir streaming HTTP konstan.
- [x] Menyediakan helper global `sse()` untuk pemanggilan mudah dari seluruh layer aplikasi.
- [x] Mengintegrasikan SSE ke modul antrean `Schedule`: TV Display (`Display.tsx`) mendengarkan aliran event `ticket_called` dan membunyikan suara Web Speech TTS secara instan (< 100ms).
- [x] Mengintegrasikan SSE ke modul `Notification`: Peringatan notifikasi in-app langsung dipancarkan ke channel pengguna saat diterbitkan.
- [x] Mengintegrasikan SSE ke modul `Seal`: Pemindaian QR code dokumen langsung memancarkan pembaruan audit ke dasbor admin.
- [x] Menyediakan perintah CLI `php aio sse:prune` untuk membersihkan rekaman event kedaluwarsa (retention cleanup).
- [x] Menyediakan React Hook reusable `resources/js/hooks/useSse.ts`.

### Non-Goals
- Fitur ini TIDAK membangun koneksi dua arah (bi-directional WebSocket), karena transmisi data dari client ke server sudah ditangani secara elegan via standar HTTP POST/PUT REST API.
- Fitur ini TIDAK bergantung pada server Redis atau Node.js pihak ketiga; seluruh antrean event disimpan di basis data relasional SQLite/MySQL/PostgreSQL terkelola aio.

---

## 3. Technical Architecture & Design

### Alur Data Real-Time
```
[Event Trigger (e.g. Schedule::callNextTicket)]
         │
         ▼
[sse()->publish('queue', 'ticket_called', payload, tenantId)]
         │
         ▼
[Database: sse_events Table]
         │
         ├──────────────────────────────────────────────┐
         ▼                                              ▼
[GET /api/sse/stream?channel=queue]          [GET /api/sse/poll?channel=queue]
(Native Browser EventSource Stream)            (Fallback Polling Endpoint)
         │                                              │
         ▼                                              ▼
[Schedule/Display.tsx (TV Display)]          [Legacy / Blocked Proxies]
(Instant Audio TTS & Display Refresh)
```

### Data Model: `sse_events` Table
```sql
CREATE TABLE IF NOT EXISTS sse_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NULL,
    channel TEXT NOT NULL,
    event TEXT NOT NULL,
    payload TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_sse_events_lookup ON sse_events(channel, id);
CREATE INDEX IF NOT EXISTS idx_sse_events_tenant ON sse_events(tenant_id, channel, id);
CREATE INDEX IF NOT EXISTS idx_sse_events_created_at ON sse_events(created_at);
```

### Interface Definition: `SseBroadcasterInterface`
```php
namespace Core\Broadcasting;

interface SseBroadcasterInterface
{
    /**
     * Publikasikan event ke channel tertentu.
     */
    public function publish(string $channel, string $event, array $payload = [], ?string $tenantId = null): int;

    /**
     * Publikasikan event publik / global tanpa batasan tenant (tenant_id = NULL).
     */
    public function publishPublic(string $channel, string $event, array $payload = []): int;

    /**
     * Ambil event terkini untuk channel dan ID tertentu (inkremental).
     */
    public function getEvents(string $channel, ?int $afterId = null, ?string $tenantId = null, int $limit = 50): array;

    /**
     * Hapus event usang yang melampaui jendela retensi (default: 60 menit).
     */
    public function prune(int $keepLastMinutes = 60): int;
}
```

---

## 4. Edge Cases & Error Scenarios
- **Koneksi Terputus / Reconnection**: Browser secara otomatis menyertakan header `Last-Event-ID` saat menyambung kembali. `SseController` melanjutkan streaming dari event berikutnya sehingga tidak ada data yang hilang.
- **Isolasi Tenant**: Channel spesifik tenant (`tenant_id IS NOT NULL`) diverifikasi secara ketat agar tidak bocor ke tenant lain. Event publik (seperti pengumuman sistem) dapat memiliki `tenant_id = NULL`.
- **Worker Hang Prevention**: Koneksi streaming dibatasi hingga 20-30 detik maksimum per request dengan periodik ping. Setelah batas waktu berakhir, stream ditutup secara anggun (*graceful close*) dan browser `EventSource` akan otomatis menyambung kembali dalam hitungan milidetik.
- **Client Disconnect**: Loop pengecekan memantau `connection_aborted()`, memutus pembacaan database seketika jika tab peramban ditutup.

---

## 5. Test Strategy
- **Unit Tests**:
  - `SseBroadcasterTest`: Uji fungsi `publish`, `publishPublic`, pembacaan berurutan `after_id`, isolasi multi-tenant, dan pembersihan `prune()`.
  - `ScheduleModuleTest`: Uji bahwa pemanggilan `ScheduleService::callNextTicket()` memicu penerbitan event `ticket_called` pada channel `queue`.
  - `SseControllerTest`: Uji format respons HTTP `text/event-stream` dan endpoint fallback `GET /api/sse/poll`.
- **TypeScript Typecheck**:
  - Verifikasi integritas type definition pada `resources/js/hooks/useSse.ts` dan `resources/js/Pages/Schedule/Display.tsx`.
- **Full Suite Regression**:
  - Memastikan seluruh test yang ada (435 tests) tetap lolos 100%.

---

## 6. Atomic Task Checklist

- [x] **Phase 1: Database Migration & Core Contracts**
  - [x] 1.1 Buat migrasi `database/migrations/2026_10_28_000031_create_sse_events_table.sql`.
  - [x] 1.2 Jalankan migrasi basis data via `php aio migrate`.
  - [x] 1.3 Buat interface `Core\Broadcasting\SseBroadcasterInterface`.

- [x] **Phase 2: SseBroadcaster Implementation & Unit Testing (TDD)**
  - [x] 2.1 Buat implementasi `Core\Broadcasting\SseBroadcaster`.
  - [x] 2.2 Daftarkan singleton di `Core\Container` / `Application`.
  - [x] 2.3 Tambahkan helper global `sse()` di `core/helpers.php`.
  - [x] 2.4 Tulis test suite `tests/Unit/SseBroadcasterTest.php` dan pastikan lolos 100%.

- [x] **Phase 3: SseController, Streaming Endpoints & CLI Console**
  - [x] 3.1 Buat controller `Core\Broadcasting\SseController` (`stream` dan `poll`).
  - [x] 3.2 Daftarkan rute `/api/sse/stream` dan `/api/sse/poll` di `routes/web.php`.
  - [x] 3.3 Tambahkan perintah konsol CLI `php aio sse:prune` di `core/Console/Kernel.php`.
  - [x] 3.4 Tulis controller test di `tests/Unit/SseControllerTest.php`.

- [x] **Phase 4: Level 2 Foundations Integration & Frontend Hook**
  - [x] 4.1 Hubungkan `ScheduleService` untuk auto-publish event `ticket_called` dan `ticket_issued`.
  - [x] 4.2 Hubungkan `NotificationService` untuk auto-publish event `notification_received`.
  - [x] 4.3 Hubungkan `SealService` untuk auto-publish event `seal_verified`.
  - [x] 4.4 Buat reusable React hook `resources/js/hooks/useSse.ts`.
  - [x] 4.5 Perbarui `resources/js/Pages/Schedule/Display.tsx` untuk mengonsumsi SSE dengan fallback polling.

- [x] **Phase 5: Full Verification, Documentation & Merge**
  - [x] 5.1 Jalankan PHPUnit test suite global (`phpunit.bat`).
  - [x] 5.2 Jalankan TypeScript typecheck (`npm run typecheck`) dan Vite build (`npm run build`).
  - [x] 5.3 Perbarui status checklist dokumen spec ini menjadi `Completed`.
  - [x] 5.4 Perbarui `specs/README.md` dan `.agents/SESSION_HANDOFF.md`.
  - [x] 5.5 Buat atomic Git commit dan merge branch ke `main`.
