# SPEC-040: Platform Resilience Watchdog, Circuit Breaker & Dead-Letter Queue (DLQ) Hardening

- **Status**: `Completed`
- **Owner / Author**: Antigravity & Lead Architect
- **Tanggal Dibuat**: 2026-10-28
- **Target File / Modul**: `core/Resilience`, `core/Queue`, `database/migrations`, `core/Console/Commands`, `core/Foundation/Application.php`, `core/helpers.php`

---

## 1. Problem Statement & Context
Dalam arsitektur SaaS mandiri *pure-PHP* (tanpa daemon eksternal seperti Redis, RabbitMQ, atau Supervisor pihak ketiga), platform mengeksekusi beban kerja komputasi, antrean asynchronous (`jobs`), interaksi API eksternal (gateway SMS/WhatsApp, SATUSEHAT/BPJS, payment callback), dan cron jobs melalui SQLite lokal dan background worker PHP.

Kondisi tersebut rentan terhadap beberapa kegagalan kaskade (*cascading failure*):
1. **API Eksternal Down Menyebabkan Worker Hang**:
   Ketika penyedia API pihak ketiga mengalami downtime atau latency tinggi (misal: timeout 30-60 detik per request), worker antrean akan terblokir (*thread starvation*), menyebabkan antrean jobs lokal membengkak ribuan dan transaksi lokal macet.
2. **Kegagalan Berulang Tanpa Backoff Cerdas (*Poison Pills*)**:
   Jobs dengan payload cacat dieksekusi berulang-ulang tanpa jeda bertingkat (*exponential backoff with jitter*), menguras siklus CPU dan mencemari log error.
3. **Dead-Letter Queue (DLQ) Pasif**:
   Tabel `failed_jobs` saat ini hanya menampung data tanpa instrumen diagnosis, replay massal terfilter, deteksi poison pill, pembersihan terprogram (*purging*), atau metrik kegagalan per antrean.
4. **Ketiadaan Platform Watchdog Terpusat**:
   Tidak ada komponen tunggal yang mengaudit kesehatan operasional platform secara menyeluruh (status antrean, kedalaman DLQ, state circuit breaker, deadlock pada locks, dan integritas SQLite WAL).

**Solusi**: Membangun fondasi **Platform Resilience Watchdog, Circuit Breaker & Dead-Letter Queue Hardening** (`Core\Resilience` & `Core\Queue`) murni berbasis PHP yang mandiri, tahan banting, menghentikan kaskade kegagalan secara *fail-fast*, dan menyediakan observabilitas real-time bagi operator sistem.

---

## 2. Goals & Non-Goals

### Goals
- [x] Menyediakan tabel basis data `circuit_breakers` untuk menyimpan status stateful antar-proses PHP.
- [x] Mengimplementasikan **Circuit Breaker Pattern** (`Core\Resilience\CircuitBreakerInterface` & `DatabaseCircuitBreaker`):
  - 3 status: `CLOSED` (normal), `OPEN` (fail-fast tanpa memanggil aksi), `HALF_OPEN` (probe pemulihan terkendali).
  - Konfigurasi ambang kegagalan (*failure threshold*), jeda pemulihan (*cooldown timeout*), dan ambang sukses (*success threshold*).
  - Eksekusi fallback elegan: `execute(callable $action, ?callable $fallback = null)`.
- [x] Hardening **Dead-Letter Queue (DLQ) Engine** (`Core\Queue\DeadLetterQueueInterface` & `DeadLetterQueue`):
  - Exponential backoff otomatis dengan jitter pada antrean retry.
  - Replay / Retry selektif per ID, per nama antrean (*queue*), atau batch sekaligus.
  - Purge aman untuk job gagal yang sudah kadaluarsa (> X hari).
  - Diagnostik metrik: kegagalan per antrean, top exceptions, dan rasio poison pill.
- [x] Mengimplementasikan **Platform Resilience Watchdog** (`Core\Resilience\PlatformWatchdogInterface` & `PlatformWatchdog`):
  - Audit komprehensif metrik kesehatan: antrean macet (*stale reserved jobs*), circuit breakers terbuka, locks kedaluwarsa tak terlepas, ukuran berkas WAL SQLite, dan kedalaman DLQ.
  - Menghasilkan DTO `PlatformHealthReport` terstruktur.
- [x] Menyediakan perintah CLI konsol:
  - `php aio watchdog:health [--json]`: Dashboard diagnostik kesehatan platform real-time.
  - `php aio circuit:list`: Tampilkan status seluruh circuit breaker terdaftar.
  - `php aio circuit:reset <service>`: Reset paksa status circuit breaker ke `CLOSED`.
  - `php aio queue:dlq:list [--queue=...] [--limit=...]`: Inspeksi pekerjaan di Dead-Letter Queue.
  - `php aio queue:dlq:retry <id|--all> [--queue=...]`: Replay pekerjaan gagal kembali ke antrean aktif.
  - `php aio queue:dlq:purge [--days=...] [--force]`: Bersihkan arsip pekerjaan gagal.
- [x] Menyediakan helper global:
  - `circuit(string $service): CircuitBreakerInterface`
  - `watchdog(): PlatformWatchdogInterface`
  - `dlq(): DeadLetterQueueInterface`

### Non-Goals
- Fitur ini TIDAK menggantikan scheduler bawaan (`schedule:run`) atau worker engine (`queue:work`), melainkan memperkuat pertahanan operasional keduanya.

---

## 3. Technical Architecture & Design

### Alur Kerja Circuit Breaker
```
            ┌───────────────────┐
            │   Status CLOSED   │◄─────────────────────────────┐
            └─────────┬─────────┘                              │
                      │                                        │
           (Kegagalan >= Threshold)               (Probe Sukses >= Threshold)
                      │                                        │
                      ▼                                        │
            ┌───────────────────┐                              │
            │    Status OPEN    │                              │
            └─────────┬─────────┘                              │
                      │                                        │
             (Cooldown Timeout Habis)                          │
                      │                                        │
                      ▼                                        │
            ┌───────────────────┐                              │
            │ Status HALF_OPEN  │──────────────────────────────┘
            └─────────┬─────────┘
                      │
           (Probe Gagal / Exception)
                      │
                      └─────────────► Kembali ke Status OPEN
```

### Data Model: `circuit_breakers`
```sql
CREATE TABLE IF NOT EXISTS circuit_breakers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    service_name TEXT NOT NULL UNIQUE,
    state TEXT NOT NULL DEFAULT 'closed', -- 'closed', 'open', 'half_open'
    failure_count INTEGER NOT NULL DEFAULT 0,
    success_count INTEGER NOT NULL DEFAULT 0,
    failure_threshold INTEGER NOT NULL DEFAULT 5,
    cooldown_seconds INTEGER NOT NULL DEFAULT 60,
    last_failure_at TEXT,
    last_success_at TEXT,
    last_state_change_at TEXT NOT NULL,
    last_error TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_circuit_breakers_service ON circuit_breakers(service_name);
```

---

## 4. Atomic Task Checklist

- [x] **Phase 1: Database Migration & Core Contracts**
  - [x] 1.1 Buat migrasi `database/migrations/2026_10_28_000034_create_circuit_breakers_table.sql`.
  - [x] 1.2 Jalankan migrasi via `php aio migrate`.
  - [x] 1.3 Buat interface `Core\Resilience\CircuitBreakerInterface`.
  - [x] 1.4 Buat interface `Core\Queue\DeadLetterQueueInterface`.
  - [x] 1.5 Buat interface `Core\Resilience\PlatformWatchdogInterface`.
  - [x] 1.6 Buat DTO `Core\Resilience\PlatformHealthReport`.

- [x] **Phase 2: Circuit Breaker Engine Implementation**
  - [x] 2.1 Buat implementasi `Core\Resilience\DatabaseCircuitBreaker`.
  - [x] 2.2 Buat `Core\Resilience\Exceptions\CircuitBreakerOpenException`.
  - [x] 2.3 Tambahkan helper global `circuit(string $service)`.

- [x] **Phase 3: Dead-Letter Queue & Resilience Watchdog Engine**
  - [x] 3.1 Buat implementasi `Core\Queue\DeadLetterQueue` (backoff, batch retry, purge, diagnostics).
  - [x] 3.2 Buat implementasi `Core\Resilience\PlatformWatchdog` (queue lag, DLQ depth, lock sanity, WAL size).
  - [x] 3.3 Tambahkan helper global `watchdog()` dan `dlq()`.
  - [x] 3.4 Daftarkan bindings di `Core\Foundation\Application.php`.

- [x] **Phase 4: CLI Console Tooling**
  - [x] 4.1 Buat perintah `php aio watchdog:health` (`core/Console/Commands/WatchdogHealthCommand.php`).
  - [x] 4.2 Buat perintah `php aio circuit:list` (`core/Console/Commands/CircuitListCommand.php`).
  - [x] 4.3 Buat perintah `php aio circuit:reset` (`core/Console/Commands/CircuitResetCommand.php`).
  - [x] 4.4 Buat perintah `php aio queue:dlq:list` (`core/Console/Commands/QueueDlqListCommand.php`).
  - [x] 4.5 Buat perintah `php aio queue:dlq:retry` (`core/Console/Commands/QueueDlqRetryCommand.php`).
  - [x] 4.6 Buat perintah `php aio queue:dlq:purge` (`core/Console/Commands/QueueDlqPurgeCommand.php`).
  - [x] 4.7 Daftarkan seluruh perintah CLI di `Application::createCliKernel()`.

- [x] **Phase 5: Unit Testing & Self-Verification**
  - [x] 5.1 Tulis unit test `tests/Unit/CircuitBreakerTest.php` (state transitions, fail-fast, fallback, recovery).
  - [x] 5.2 Tulis unit test `tests/Unit/DeadLetterQueueTest.php` (retry batch, backoff, purge, metrics).
  - [x] 5.3 Tulis unit test `tests/Unit/PlatformWatchdogTest.php` (health aggregation, report generation).
  - [x] 5.4 Jalankan PHPUnit test suite global dan pastikan 100% lolos.
  - [x] 5.5 Jalankan TypeScript typecheck (`npm run typecheck`) dan Vite build (`npm run build`).
  - [x] 5.6 Perbarui status dokumen spec ini menjadi `Completed`.
  - [x] 5.7 Perbarui `specs/README.md` dan `.agents/SESSION_HANDOFF.md`.
  - [x] 5.8 Buat atomic Git commit dan merge branch ke `main`.
