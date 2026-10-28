# SPEC-037: Universal Atomic Mutex & Distributed Concurrency Lock Engine

- **Status**: `Completed`
- **Owner / Author**: Antigravity & Lead Architect
- **Tanggal Dibuat**: 2026-10-28
- **Target File / Modul**: `core/Lock`, `core/Sequence/SequenceManager.php`, `app/Modules/Schedule`, `app/Modules/Inventory`, `core/Console/Commands`

---

## 1. Problem Statement & Context
Dalam aplikasi web modular SaaS berskala produksi, operasi-operasi sensitif sering kali diakses oleh banyak pengguna atau terminal secara bersamaan (*concurrent requests*). Tanpa mekanisme penguncian atomik lintas proses (*atomic mutex / concurrency lock*), sistem rentan terhadap bahaya fatal (*race conditions*):

1. **Double-Spending & Negative Stock di Inventori**:
   Dua loket kasir atau apoteker meresepkan item obat yang sama saat sisa stok batch FEFO tinggal 2 unit. Kedua request membaca stok = 2, keduanya memotong stok sebesar 2 unit, menyebabkan stok fisik jatuh ke angka negatif (`-2`) dan terjadi penjualan ganda (*overselling*).
2. **Tabrakan Nomor Dokumen & Sequence Duplikat**:
   Dua faktur tagihan atau nomor antrean yang diterbitkan pada milidetik yang sama berisiko menghasilkan nomor transaksi yang identik jika proses pembacaan urutan (`MAX(sequence) + 1`) terjadi bersamaan sebelum salah satunya tersimpan di database.
3. **Panggilan Antrean Ganda**:
   Dua loket yang menekan tombol pemanggilan tiket berikutnya pada saat yang bersamaan dapat memanggil nomor tiket antrean yang sama.
4. **Alokasi Ruangan / Bed Bertabrakan**:
   Dua staf perawat mendaftarkan dua pasien berbeda ke bed ruang rawat yang sama secara simultan.

**Solusi**: Membangun **Universal Atomic Mutex & Concurrency Lock Engine** (`Core\Lock`) murni berbasis PHP tanpa dependensi daemon Redis pihak ketiga (menggunakan driver database atomik berbasis tabel `locks` dan driver file `flock`). Setiap lock wajib memiliki masa kedaluwarsa otomatis (*Time-To-Live auto-release*) untuk mengeliminasi risiko *deadlock* jika proses pekerja mati di tengah jalan (*process crash/abort*).

---

## 2. Goals & Non-Goals

### Goals
- [x] Menyediakan tabel basis data `locks` dengan primary key `key` dan indeks waktu `expires_at` untuk akuisisi atomik tingkat baris database.
- [x] Menyediakan kontrak `Core\Lock\LockInterface` dan `Core\Lock\LockManagerInterface`.
- [x] Menyediakan `Core\Lock\Drivers\DatabaseLockDriver` (driver utama) dan `Core\Lock\Drivers\FileLockDriver` (driver fallback OS flock).
- [x] Menyediakan mekanisme *auto-expiration TTL* (Time-To-Live) anti-deadlock dan validasi identitas kepemilikan (*owner token*) agar lock hanya bisa dilepas oleh pemilik sahnya.
- [x] Menyediakan fungsi antrean tunggu cerdas (*wait timeout with micro-sleep backoff*) sehingga proses yang antre tidak langsung gagal, melainkan menunggu giliran hingga batas milidetik tertentu.
- [x] Menyediakan wrapper eksekusi aman `lock()->run('key', fn() => ..., ttl: 10, waitTimeoutMs: 3000)`.
- [x] Menyediakan helper global `lock()` di `core/helpers.php`.
- [x] Mengintegrasikan penguncian otomatis pada titik kritis:
  - `SequenceManager::getNextNumber()`: Proteksi penomoran urut dokumen/faktur.
  - `ScheduleService::issueQueueTicket()` & `callNextTicket()`: Proteksi nomor antrean dan pemanggilan loket.
  - `InventoryService::deductStockFefo()`: Proteksi pemotongan batch stok dari race condition.
- [x] Menyediakan perintah CLI konsol `php aio lock:prune` untuk membersihkan rekaman lock yang kedaluwarsa dan `php aio lock:list` untuk memantau lock aktif.

### Non-Goals
- Fitur ini TIDAK mewajibkan server Redis atau ZooKeeper eksternal, menjaga filosofi arsitektur *aio* yang independen, mandiri (*zero-dependency*), dan portabel untuk solo developer maupun perusahaan.

---

## 3. Technical Architecture & Design

### Alur Mutex & Concurrency Guard
```
[Concurrent Request 1] ──> lock()->run('inv:item_42', fn() => deductStock(42, 2)) ──┐
                                                                                     │ (Acquires Lock: SUCCESS)
                                                                                     ▼
                                                                           [Database `locks` Table]
                                                                                     ▲
[Concurrent Request 2] ──> lock()->run('inv:item_42', fn() => deductStock(42, 2)) ──┘
                             (Waits 50ms... Lock Released! ──> Reads updated stock = 0 ──> Rejects with Insufficient Stock)
```

### Data Model: `locks` Table
```sql
CREATE TABLE IF NOT EXISTS locks (
    key TEXT PRIMARY KEY,
    owner TEXT NOT NULL,
    expires_at INTEGER NOT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_locks_expires_at ON locks(expires_at);
```

### Interface Definition
```php
namespace Core\Lock;

interface LockInterface
{
    public function acquire(int $waitTimeoutMs = 0): bool;
    public function release(): bool;
    public function isAcquired(): bool;
    public function getKey(): string;
    public function getOwner(): string;
    public function getTtl(): int;
}

interface LockManagerInterface
{
    public function lock(string $resourceKey, int $ttlSeconds = 10, ?string $owner = null): LockInterface;
    public function acquire(string $resourceKey, int $ttlSeconds = 10, int $waitTimeoutMs = 0, ?string $owner = null): ?LockInterface;
    public function run(string $resourceKey, callable $callback, int $ttlSeconds = 10, int $waitTimeoutMs = 3000, ?string $owner = null): mixed;
    public function release(string $resourceKey, ?string $owner = null): bool;
    public function isLocked(string $resourceKey): bool;
    public function pruneExpired(): int;
}
```

---

## 4. Edge Cases & Error Scenarios
- **Worker Crash Saat Menahan Lock**: Jika proses PHP fatal error atau listrik server mati saat lock sedang dipegang, kolom `expires_at` akan kedaluwarsa setelah TTL (misal 10 detik). Request berikutnya yang masuk akan otomatis membersihkan lock usang tersebut tanpa macet permanen.
- **Lock Hijacking Protection**: Lock dilepas hanya jika token `owner` cocok dengan token pemilik saat akuisisi dibuat, mencegah Request B melepas lock milik Request A.
- **High Concurrency Wait Contention**: Jika ada 10 proses meminta lock yang sama, mekanisme `waitTimeoutMs` melakukan polling dengan interval adaptif 20-50ms (`usleep`) hingga batas timeout tercapai, melempar `LockTimeoutException` jika lock tetap terkunci.

---

## 5. Test Strategy
- **Unit Tests**:
  - `LockAcquireAndReleaseTest`: Uji alur akuisisi berhasil dan pelepasan lock.
  - `LockMutualExclusionTest`: Uji request kedua gagal seketika saat lock masih dipegang request pertama.
  - `LockWaitTimeoutTest`: Uji request kedua berhasil masuk setelah request pertama selesai dalam jendela `waitTimeoutMs`.
  - `LockTtlAutoExpirationTest`: Uji bahwa lock yang telah kedaluwarsa otomatis dapat diambil kembali.
  - `LockOwnerSafetyTest`: Uji token owner mencegah pelepasan lock oleh entitas lain.
  - `LockRunClosureTest`: Uji eksekusi closure otomatis melepaskan lock meskipun callback melempar exception.
- **Integration Tests**:
  - `SequenceConcurrencyLockTest`: Uji penomoran urut dokumen tidak bertabrakan saat dieksekusi berurutan/paralel.
  - `ScheduleQueueLockTest`: Uji pemanggilan antrean dan penerbitan tiket bebas dari nomor ganda.
  - `InventoryFefoLockTest`: Uji pemotongan stok FEFO di bawah beban konkurensi.
- **Full Suite Regression**:
  - Memastikan seluruh 435 tests yang ada tetap 100% PASS.

---

## 6. Atomic Task Checklist

- [x] **Phase 1: Database Migration & Core Contracts**
  - [x] 1.1 Buat migrasi `database/migrations/2026_10_28_000032_create_locks_table.sql`.
  - [x] 1.2 Jalankan migrasi basis data via `php aio migrate`.
  - [x] 1.3 Buat interface `Core\Lock\LockInterface` dan `Core\Lock\LockManagerInterface`.
  - [x] 1.4 Buat interface driver `Core\Lock\Drivers\LockDriverInterface`.

- [x] **Phase 2: Drivers & Lock Engine Implementation (TDD)**
  - [x] 2.1 Buat implementasi `Core\Lock\Drivers\DatabaseLockDriver`.
  - [x] 2.2 Buat implementasi `Core\Lock\Drivers\FileLockDriver`.
  - [x] 2.3 Buat implementasi `Core\Lock\Lock` dan `Core\Lock\LockManager`.
  - [x] 2.4 Daftarkan binding di `Core\Foundation\Application.php`.
  - [x] 2.5 Tambahkan helper global `lock()` di `core/helpers.php`.
  - [x] 2.6 Tulis unit test komprehensif `tests/Unit/LockEngineTest.php` dan pastikan lolos 100%.

- [x] **Phase 3: CLI Console Tooling & Diagnostics**
  - [x] 3.1 Buat perintah `php aio lock:prune` di `core/Console/Commands/LockPruneCommand.php`.
  - [x] 3.2 Buat perintah `php aio lock:list` di `core/Console/Commands/LockListCommand.php`.
  - [x] 3.3 Daftarkan perintah CLI di `Application::createCliKernel()`.

- [x] **Phase 4: Level 1 & Level 2 Critical Path Integration**
  - [x] 4.1 Pasang guard atomic lock di `Core\Sequence\SequenceManager::getNextNumber()`.
  - [x] 4.2 Pasang guard atomic lock di `App\Modules\Schedule\Services\ScheduleService` (`issueQueueTicket` dan `callNextTicket`).
  - [x] 4.3 Pasang guard atomic lock di `App\Modules\Inventory\Services\InventoryService` (`deductStockFefo`).
  - [x] 4.4 Tulis test integrasi penguncian di `tests/Unit/LockIntegrationTest.php`.

- [x] **Phase 5: Full Verification, Documentation & Merge**
  - [x] 5.1 Jalankan PHPUnit test suite global (`phpunit.bat`).
  - [x] 5.2 Jalankan TypeScript typecheck (`npm run typecheck`) dan Vite build (`npm run build`).
  - [x] 5.3 Perbarui status checklist dokumen spec ini menjadi `Completed`.
  - [x] 5.4 Perbarui `specs/README.md` dan `.agents/SESSION_HANDOFF.md`.
  - [x] 5.5 Buat atomic Git commit dan merge branch ke `main`.
