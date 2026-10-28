# SPEC-043: Universal Runtime Performance Sentinel & SQLite Profiling Engine

- **Status**: `Completed`
- **Owner / Author**: Antigravity & Lead Architect
- **Tanggal Dibuat**: 2026-10-28
- **Target File / Modul**: `core/Diagnostics`, `core/Database/Connection.php`, `core/Console/Commands`, `core/Foundation/Application.php`, `core/helpers.php`

---

## 1. Problem Statement & Context
Dalam arsitektur SaaS modular universal berbasis basis data tertanam SQLite dengan mode WAL (Write-Ahead Logging), seluruh baca-tulis terjadi pada satu file terpusat tanpa server daemon terpisah (Zero External Daemons).
Meskipun SQLite WAL sangat cepat (bisa melayani 100.000+ query baca per detik), arsitektur ini rentan terhadap degradasi performa (*performance degradation*) jika:
1. **Slow Unindexed Queries**: Query tanpa indeks memindai seluruh tabel (*full table scan*) dan menahan lock baca/tulis terlalu lama.
2. **Lock Contention (`SQLITE_BUSY`)**: Transaksi penulisan masif atau lambat yang tidak dioptimalkan dapat memicu lock wait time-out pada proses lain.
3. **Memory Bloat & Leaks**: Worker background job atau proses HTTP panjang yang mengalokasikan data besar (> 64MB - 128MB) tanpa pembersihan memori dapat menyebabkan Out-Of-Memory (OOM) mendadak di lingkungan hosting terbatas/edge.

Untuk mencegah bencana performa ini secara proaktif, platform membutuhkan **Universal Runtime Performance Sentinel & Profiler** murni PHP yang bekerja secara transparan, ringan (*zero overhead*), dan offline-first.

---

## 2. Goals & Non-Goals

### Goals
- [x] **Query Performance Profiling (`QueryProfiler`)**:
  - Mengukur durasi eksekusi statement SQL secara sub-milidetik.
  - Mendeteksi query lambat (*slow queries*) yang melampaui ambang batas (default: 25ms dev / 50ms prod).
  - Menyimpan ring-buffer query lambat beserta parameter, durasi, dan backtrace pemanggil.
- [x] **Memory Sentinel (`MemorySentinel`)**:
  - Memantau alokasi memori real-time (`memory_get_usage(true)`) dan puncak penggunaan (`memory_get_peak_usage(true)`).
  - Memberikan peringatan (*high-water mark warnings*) jika ambang batas memori (misal: 64MB) terlampaui.
- [x] **Lock Contention & Benchmarking Monitor (`PerformanceSentinel`)**:
  - Tolok ukur (*benchmark*) throughput baca/tulis SQLite WAL dan latensi akuisisi mutex/lock.
  - Diagnosis real-time status kesehatan runtime sistem.
- [x] **CLI Tooling & Global Helper**:
  - Perintah `php aio sentinel:profile` (benchmark latensi & throughput SQLite WAL).
  - Perintah `php aio sentinel:slow-queries` (tampilkan daftar query lambat terdeteksi).
  - Perintah `php aio sentinel:status` (ringkasan memori, query count, dan metrik runtime).
  - Global helper `sentinel(): PerformanceSentinelInterface`.

### Non-Goals
- Tidak memerlukan ekstensi PHP eksternal khusus (seperti Xdebug atau Tideways) yang membebani server produksi.
- Tidak mengirim data metrik ke cloud pihak ketiga (tetap 100% lokal dan offline-first).

---

## 3. Technical Architecture & Design

### Alur Arsitektur
```
[Database / HTTP / CLI Operations]
         │
         ▼
[PerformanceSentinel (core/Diagnostics)]
   ├── QueryProfiler ───────> Catat waktu eksekusi SQL & buffer slow queries
   ├── MemorySentinel ──────> Pantau alokasi memori & deteksi lonjakan (spikes)
   └── BenchmarkRunner ─────> Pengujian throughput & contention lock SQLite
```

---

## 4. Atomic Task Checklist

- [x] **Phase 1: Contracts & Diagnostics Core**
  - [x] 1.1 Buat interface `Core\Diagnostics\PerformanceSentinelInterface`.
  - [x] 1.2 Buat `Core\Diagnostics\QueryProfiler` (ring buffer, thresholding, query metrics).
  - [x] 1.3 Buat `Core\Diagnostics\MemorySentinel` (memory peak tracking, leak detector).
  - [x] 1.4 Buat `Core\Diagnostics\PerformanceSentinel` (koordinator diagnosa dan benchmark).

- [x] **Phase 2: Database Connection Integration**
  - [x] 2.1 Hubungkan `Connection` dengan `QueryProfiler` untuk pengukuran otomatis query execution.
  - [x] 2.2 Daftarkan bindings di `Core\Foundation\Application.php`.
  - [x] 2.3 Tambahkan global helper `sentinel()`.

- [x] **Phase 3: CLI Console Tooling**
  - [x] 3.1 Buat perintah `php aio sentinel:profile` (`core/Console/Commands/SentinelProfileCommand.php`).
  - [x] 3.2 Buat perintah `php aio sentinel:slow-queries` (`core/Console/Commands/SentinelSlowQueriesCommand.php`).
  - [x] 3.3 Buat perintah `php aio sentinel:status` (`core/Console/Commands/SentinelStatusCommand.php`).
  - [x] 3.4 Daftarkan perintah CLI di `Application::createCliKernel()`.

- [x] **Phase 4: Unit Testing & Self-Verification**
  - [x] 4.1 Tulis unit test komprehensif `tests/Unit/PerformanceSentinelTest.php`.
  - [x] 4.2 Jalankan PHPUnit test suite global dan pastikan 100% lolos.
  - [x] 4.3 Jalankan TypeScript typecheck (`npm run typecheck`) dan Vite build (`npm run build`).
  - [x] 4.4 Perbarui status dokumen spec ini menjadi `Completed`.
  - [x] 4.5 Perbarui `specs/README.md` dan `.agents/SESSION_HANDOFF.md`.
  - [x] 4.6 Buat atomic Git commit dan merge branch ke `main`.
