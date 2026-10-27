# SPEC-032: Universal Scheduling, Appointments & Human Queue Engine

- **Status**: `Draft`
- **Owner / Author**: Antigravity Platform Team
- **Tanggal Dibuat**: 2026-10-28
- **Target Modul**: `app/Modules/Schedule/`
- **Tingkat Arsitektur**: `Level 2 (Shared Business Foundation)`

---

## 1. Problem Statement & Context
Dalam setiap operasional bisnis jasa, fasilitas kesehatan, organisasi pelayanan publik, maupun event:
1. **Dua Sisi Pengelolaan Pelayanan**:
   - **Terencana / Perjanjian (*Scheduled / Appointments*)**: Pasien/Klien memesan slot waktu terlebih dahulu (e.g., booking sesi dokter gigi jam 14:00, booking service mobil jam 09:00, reservasi ruang konsultasi hukum).
   - **Langsung / Antrean Fisik (*Walk-in / Live Queue*)**: Pasien/Klien datang langsung ke lokasi, mengambil nomor tiket antrean di mesin anjungan/kios, menunggu di ruang tunggu sambil memantau layar TV panggilan suara.
2. **Ketiadaan Mesin Antrean Manusia Universal (*Human Queue Engine*)**:
   - Platform saat ini memiliki *Queue Worker* di Core (SPEC-011), namun itu adalah *Background Job Queue* untuk proses backend (bukan antrean manusia).
   - Tanpa modul Level 2 yang universal, modul bisnis klinik atau customer service akan membuat tabel antrean loket sendiri-sendiri, padahal logika antrean (nomor tiket per loket, panggilan audio, layar display, transfer antrean, status tunggu) adalah 100% universal!
3. **Keterhubungan dengan Fondasi Lain**:
   - **Contact (SPEC-027)**: Pasien / Pelanggan (`client_contact_id`) & Dokter / Petugas (`staff_contact_id`).
   - **Facility (SPEC-031)**: Di ruangan mana (`room_id`) dan di meja/loket/bed mana (`space_id`) antrean/janji temu berlangsung.
   - **Billing (SPEC-030)**: Setelah pelayanan selesai, tiket antrean atau appointment langsung dapat dikonversi menjadi invoice tagihan pelayanan.
   - **Device Toolkit (SPEC-024)**: Cetak nomor tiket fisik ke printer thermal kiosk pendaftaran.

Solusinya adalah membangun **Modul Level 2: `Schedule` (Universal Scheduling, Appointments & Human Queue Engine)** yang mengelola jadwal sumber daya, janji temu (*appointment*), dan siklus antrean manusia (*queue calling engine*) secara terpadu.

---

## 2. Goals & Non-Goals

### Goals
- **Jadwal Ketersediaan Sumber Daya (`resource_schedules`)**:
  - Penjadwalan ketersediaan staf/dokter/konsultan (`staff_contact_id`) di ruangan tertentu (`room_id` dari SPEC-031).
  - Pengaturan hari dalam seminggu (`day_of_week`: 0=Minggu, 1=Senin, ..., 6=Sabtu), jam mulai (`start_time`), jam selesai (`end_time`), dan kuota maksimal per sesi (`quota`).
- **Janji Temu / Reservasi Slot Waktu (`appointments`)**:
  - Pemesanan slot waktu pelayanan oleh pelanggan/pasien (`client_contact_id`).
  - Penomoran kode booking otomatis: `APT/2026/00001` via sequence.
  - Tanggal, jam mulai, jam selesai, catatan keluhan/keperluan.
  - Status siklus: `booked`, `confirmed`, `checked_in`, `in_progress`, `completed`, `cancelled`, `no_show`.
- **Mesin Antrean Fisik / Loket Manusia (`queue_tickets`)**:
  - Penerbitan nomor antrean harian per grup/layanan (e.g., `A-001` untuk Poli Umum / CS, `B-001` untuk Poli Gigi / Kasir, `F-001` untuk Farmasi).
  - Nomor urut direset per hari via sequence harian (`daily reset`).
  - Status siklus tiket: `waiting` $\rightarrow$ `called` $\rightarrow$ `serving` $\rightarrow$ `transferred` $\rightarrow$ `completed` / `skipped`.
  - Perekaman waktu panggil (*called_at*), waktu mulai layan (*serving_at*), dan waktu selesai (*completed_at*) untuk metrik waktu tunggu (*waiting time analytics*).
- **Display Ruang Tunggu & Text-to-Speech Ready**:
  - State antrean aktif tersimpan di *In-Memory Cache* (SPEC-020) berkecepatan tinggi untuk pooling / SSE display TV tanpa membebani database utama.
  - Payload data panggil memuat format string Text-to-Speech (TTS): *"Nomor antrean A-001 silakan menuju ke Poli Umum Loket 2"*.
- **Exported Shared Contract Interface (`ScheduleRegistryInterface`)**:
  - Global helper: `schedule(): ScheduleRegistryInterface` (alias: `appointments()`, `humanQueue()`).
  - Method instan: `createAppointment()`, `checkInAppointment()`, `issueQueueTicket()`, `callNextTicket()`, `recallTicket()`, `completeTicket()`, `skipTicket()`, `getDisplayQueueState()`.
- **Web UI & REST API**:
  - Web UI untuk pemanggilan antrean oleh dokter/petugas loket (*Calling Console*).
  - Tampilan layar penuh responsif untuk TV Display Ruang Tunggu (*Waiting Room Screen*).
  - REST API endpoint untuk integrasi mesin anjungan mandiri (APM Kiosk) dan aplikasi antrean online.

### Non-Goals
- Tidak mencakup algoritma kalender astrologi atau zonasi waktu luar angkasa.

---

## 3. Technical Architecture & Design

### Alur Antrean & Integrasi
```
┌────────────────────────────────────────────────────────────────────────┐
│               [Kios Anjungan / APM / Online Booking]                   │
│                                  │                                     │
│  • issueQueueTicket()            ▼                                     │
│  • createAppointment() ──► checkInAppointment()                        │
│                                  │                                     │
│                                  ▼                                     │
│                    Tiket Antrean: Status 'waiting'                     │
│                                  │                                     │
│    ┌─────────────────────────────┴─────────────────────────────┐       │
│    ▼                                                           ▼       │
│ [Konsol Petugas / Dokter]                             [Layar TV Display]│
│  • callNextTicket() ────► Update Cache State ────────► Panggilan Audio │
│  • servingTicket()                                     & Nomor Aktif   │
│  • completeTicket() ────► Trigger billing()->createInvoice() (Opsional)│
└────────────────────────────────────────────────────────────────────────┘
```

### Data Model & Database Schema

```
┌────────────────────────────────────────────────────────────────────────┐
│                          resource_schedules                            │
├────────────────────────────────────────────────────────────────────────┤
│ id (PK, int)                                                           │
│ tenant_id (FK, int)                                                    │
│ staff_contact_id (FK contacts, nullable)                               │
│ room_id (FK facilities_rooms, nullable)                                │
│ service_item_id (FK items, nullable)                                   │
│ day_of_week (int: 0=Minggu, 1=Senin, ..., 6=Sabtu)                     │
│ start_time (varchar: '08:00')                                          │
│ end_time (varchar: '12:00')                                            │
│ quota (int, default 30)                                                │
│ is_active (bool, default true)                                         │
│ created_at, updated_at                                                 │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │ 1:N
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│                              appointments                              │
├────────────────────────────────────────────────────────────────────────┤
│ id (PK, int)                                                           │
│ tenant_id (FK, int)                                                    │
│ appointment_number (varchar, unique per tenant) e.g. APT/2026/00001    │
│ client_contact_id (FK contacts, nullable)                              │
│ client_name (varchar)                                                  │
│ staff_contact_id (FK contacts, nullable)                               │
│ room_id (FK facilities_rooms, nullable)                                │
│ appointment_date (date/varchar)                                        │
│ start_time (varchar: '09:00')                                          │
│ end_time (varchar: '09:30', nullable)                                  │
│ status ('booked'|'confirmed'|'checked_in'|'in_progress'|'completed'|  │
│         'cancelled'|'no_show')                                         │
│ notes (text, nullable)                                                 │
│ created_at, updated_at                                                 │
└────────────────────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────────────────────┐
│                             queue_tickets                              │
├────────────────────────────────────────────────────────────────────────┤
│ id (PK, int)                                                           │
│ tenant_id (FK, int)                                                    │
│ ticket_number (varchar) e.g. A-001, B-012, F-003                       │
│ prefix (varchar, default 'A') e.g. 'A', 'B', 'F'                       │
│ sequence_number (int) e.g. 1, 2, 3                                     │
│ ticket_date (date/varchar) e.g. '2026-10-28'                           │
│ service_name (varchar) e.g. Poli Umum, Loket Pendaftaran, Farmasi      │
│ room_id (FK facilities_rooms, nullable)                                │
│ space_id (FK facilities_spaces, nullable) e.g. Loket 1, Meja 2         │
│ client_contact_id (FK contacts, nullable)                              │
│ client_name (varchar, default 'Pengunjung Umum')                       │
│ appointment_id (FK appointments, nullable)                             │
│ status ('waiting'|'called'|'serving'|'transferred'|'completed'|'skipped'│
│ called_at (timestamp, nullable)                                        │
│ serving_at (timestamp, nullable)                                       │
│ completed_at (timestamp, nullable)                                     │
│ called_by (int, nullable - user_id)                                    │
│ created_at, updated_at                                                 │
└────────────────────────────────────────────────────────────────────────┘
```

### Shared Contract Interface (`ScheduleRegistryInterface`)
```php
namespace App\Modules\Schedule\Contracts;

interface ScheduleRegistryInterface
{
    // Appointments
    public function createAppointment(array $data): array;
    public function getAppointment(int $id): ?array;
    public function updateAppointment(int $id, array $data): array;
    public function checkInAppointment(int $id): array;
    public function listAppointments(array $filters = []): array;
    public function cancelAppointment(int $id, ?string $reason = null): array;

    // Human Queue System
    public function issueQueueTicket(array $data): array;
    public function getQueueTicket(int $id): ?array;
    public function callNextTicket(string $prefix, ?int $roomId = null, ?int $spaceId = null, ?int $userId = null): ?array;
    public function recallTicket(int $ticketId): array;
    public function startServing(int $ticketId): array;
    public function completeTicket(int $ticketId): array;
    public function skipTicket(int $ticketId): array;
    public function transferTicket(int $ticketId, string $targetPrefix, ?int $targetRoomId = null): array;
    
    // Live Display & Analytics
    public function getDisplayQueueState(): array;
    public function listWaitingTickets(string $prefix, ?string $date = null): array;
    public function getQueueMetrics(?string $date = null): array;
}
```

---

## 4. Edge Cases & Error Scenarios
- **Kasus 1: Tiket Antrean Habis / Duplikasi Nomor Tiket Hari Ini**: Reset sequence harian per prefix (`daily reset`) memastikan nomor kembali ke 001 di awal pergantian tanggal kalender secara atomik.
- **Kasus 2: Pemanggilan Antrean Saat Antrean Kosong**: `callNextTicket()` mengembalikan `null` dengan anggun tanpa melempar runtime exception.
- **Kasus 3: Pasien Melewatkan Panggilan (*Skipped / No-Show*)**: Petugas dapat menandai `skipTicket()` dan sistem mencatat riwayat pemanggilan, lalu dapat dipanggil ulang jika pasien hadir kembali.
- **Kasus 4: Isolasi Multi-Tenant Mutlak**: Nomor tiket dan jadwal terkunci rapat per `tenant_id` untuk mencegah percampuran antrean antar-cabang/tenant.

---

## 5. Test Strategy
- **Unit & Integration Test Suite** (`tests/Unit/ScheduleModuleTest.php`):
  1. `test_can_create_appointment_and_check_in_to_queue`
  2. `test_issue_queue_tickets_generates_sequential_daily_ticket_numbers`
  3. `test_calling_next_ticket_transitions_state_from_waiting_to_called`
  4. `test_start_serving_and_completing_ticket_records_lifecycle_timestamps`
  5. `test_transfer_ticket_to_another_service_creates_connected_sequence`
  6. `test_display_queue_state_returns_active_tickets_per_service`
  7. `test_multi_tenant_isolation_in_scheduling_and_queues`
- **Verification Gate**:
  - PHPUnit test suite 100% pass.
  - TypeScript typecheck (`tsc --noEmit`) 100% clean.
  - Pre-commit verification (`scripts/verify.ps1`).

---

## 6. Atomic Task Checklist

- [ ] **Phase 1: Setup Modul, Arsitektur, & Database Migrations**
  - [ ] 1.1 Buat manifest modul `app/Modules/Schedule/module.json`.
  - [ ] 1.2 Buat migration `database/migrations/2026_10_28_000026_create_schedule_and_queue_tables.sql` untuk tabel `resource_schedules`, `appointments`, dan `queue_tickets`.
  - [ ] 1.3 Jalankan migrasi via console runner dan verifikasi skema database.
- [ ] **Phase 2: Service Contracts & Core Engine Implementation (TDD)**
  - [ ] 2.1 Buat interface `App\Modules\Schedule\Contracts\ScheduleRegistryInterface`.
  - [ ] 2.2 Buat Service Provider `App\Modules\Schedule\ScheduleServiceProvider` dan daftarkan ke modul.
  - [ ] 2.3 Daftarkan helper global `schedule()` di `core/helpers.php`.
  - [ ] 2.4 Tulis test suite `tests/Unit/ScheduleModuleTest.php` mencakup appointment, tiket antrean, state machine pemanggilan, display state, dan tenant isolation.
  - [ ] 2.5 Implementasikan `App\Modules\Schedule\Services\ScheduleService` hingga seluruh skenario unit test lolos 100%.
- [ ] **Phase 3: Web UI, Routes, & Programmatic REST API**
  - [ ] 3.1 Buat routes modul di `app/Modules/Schedule/routes.php`.
  - [ ] 3.2 Implementasikan Web Controller `ScheduleController` untuk konsol pemanggil antrean dan layar TV display ruang tunggu.
  - [ ] 3.3 Implementasikan REST API Controller `ApiScheduleController` untuk mesin anjungan APM kiosk pendaftaran dan integrasi mobile.
  - [ ] 3.4 Buat view template atau halaman UI responsif dark/light mode untuk pemanggil antrean dan TV display.
- [ ] **Phase 4: Final Verification, Review & Handoff**
  - [ ] 4.1 Jalankan seluruh test suite global via `vendor/bin/phpunit.bat` (memastikan tidak ada regresi).
  - [ ] 4.2 Jalankan `npm run typecheck` dan script `scripts/verify.ps1`.
  - [ ] 4.3 Perbarui `specs/README.md` dan `.agents/SESSION_HANDOFF.md`.
  - [ ] 4.4 Merge atomic commit ke branch `main`.
