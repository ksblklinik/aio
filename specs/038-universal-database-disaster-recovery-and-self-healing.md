# SPEC-038: Universal Database Disaster Recovery, Online Hot-Backup & Database Self-Healing Engine

- **Status**: `Completed`
- **Owner / Author**: Antigravity & Lead Architect
- **Tanggal Dibuat**: 2026-10-28
- **Target File / Modul**: `core/Backup`, `core/Console/Commands`, `core/Foundation/Application.php`, `core/helpers.php`

---

## 1. Problem Statement & Context
Dalam arsitektur SaaS yang mengelola data penting (finansial, rekam medis, inventori, faktur, segel TTE kriptografis), kehilangan atau kerusakan basis data (*data corruption / loss*) adalah bencana mutlak (*catastrophic failure*). Di lingkungan produksi modern:
1. **Risiko Kerusakan Berkas SQLite (*Split-Page / Torn Writes*)**:
   Jika server mati mendadak saat proses penulisan intensif atau jika berkas `.sqlite` disalin mentah (*raw file copy*) saat transaksi WAL sedang aktif, salinan cadangan sering kali rusak dan tidak dapat dibaca kembali (*corrupted snapshot*).
2. **Kebutuhan Online Non-Blocking Hot Backup**:
   Sistem harus dapat membuat cadangan data secara konsisten dan atomik tanpa mematikan aplikasi (*zero downtime*), tanpa mengunci transaksi baca/tulis yang sedang berlangsung.
3. **Keamanan Cadangan (*Encryption at Rest*)**:
   File cadangan yang disimpan di disk server atau dikirim ke media eksternal rentan disadap atau dicuri jika tidak dienkripsi secara kriptografis (AES-256-GCM terotentikasi).
4. **Verifikasi Integritas Otomatis & Pemulihan Mandiri (*Self-Healing*)**:
   Sistem harus dapat mendeteksi indeks korup, foreign key yang terputus, atau fragmentasi berlebih, serta mampu melakukan pemulihan otomatis (*healing/checkpoint/reindex*) sebelum masalah meluas menjadi kegagalan sistemik.
5. **Kemudahan Restore dengan Jaring Pengaman (*Safe Rollback/Restore*)**:
   Proses pemulihan cadangan (`db:restore`) wajib mengambil *pre-restore snapshot* terlebih dahulu sebelum menimpa data, menjamin operator tidak pernah kehilangan data terbaru secara tidak sengaja.

---

## 2. Goals & Non-Goals

### Goals
- [ ] Menyediakan kontrak `Core\Backup\BackupManagerInterface` dan implementasi `Core\Backup\BackupManager`.
- [ ] Menyediakan driver cadangan `Core\Backup\Drivers\SqliteBackupDriver` yang memanfaatkan mekanisme atomik SQLite `VACUUM INTO` untuk *non-blocking hot-backup*.
- [ ] Menyediakan driver cadangan `Core\Backup\Drivers\MysqlBackupDriver` untuk kompatibilitas multi-database engine.
- [ ] Menyediakan fitur kompresi hemat ruang murni PHP (`gzencode`/`gzdecode`) dengan rasio kompresi tinggi (hingga 70-85%).
- [ ] Menyediakan enkripsi simetris terotentikasi AES-256-GCM menggunakan secret key aplikasi (`APP_KEY`) untuk melindungi data cadangan di disk.
- [ ] Menyediakan manifes metadata JSON di setiap paket cadangan: ID cadangan, timestamp, versi migrasi, hitungan baris per tabel penting, checksum SHA-256 berkas asli dan terenkripsi.
- [ ] Menyediakan engine pemeriksaan integritas `Core\Backup\Integrity\DatabaseIntegrityChecker` (`PRAGMA integrity_check`, `PRAGMA foreign_key_check`, dan verifikasi skema migrasi).
- [ ] Menyediakan engine pemulihan mandiri `Core\Backup\SelfHealing\DatabaseSelfHealer` untuk membersihkan WAL terfragmentasi (`wal_checkpoint(TRUNCATE)`), perbaikan indeks (`REINDEX`), dan optimasi vacuum (`VACUUM`).
- [ ] Menyediakan perintah CLI konsol terintegrasi:
  - `php aio db:backup [--encrypt] [--compress] [--tag=...]`: Membuat hot-backup instan.
  - `php aio db:restore <file> [--force]`: Memulihkan database dengan proteksi snapshot darurat.
  - `php aio db:verify`: Audit integritas menyeluruh dan laporan kesehatan database.
  - `php aio db:heal`: Menjalankan pembersihan WAL, reindex, dan penyembuhan fragmentasi.
  - `php aio db:prune-backups [--keep=10]`: Pembersihan otomatis cadangan lama sesuai retensi.
- [ ] Menyediakan helper global `backup()` di `core/helpers.php`.
- [ ] Menambahkan otomatisasi backup terjadwal di `ScheduleRunner`.

### Non-Goals
- Fitur ini TIDAK bergantung pada tool baris perintah OS eksternal (seperti `mysqldump` OS binary atau sqlite3 CLI), melainkan berjalan murni di atas ekstensi PDO PHP untuk portabilitas universal.

---

## 3. Technical Architecture & Design

### Alur Kerja Hot Backup & Disaster Recovery
```
[User / CLI: php aio db:backup]
                │
                ▼
      [SqliteBackupDriver] ──(VACUUM INTO)──> [Consistent Temp DB]
                │
                ▼
     [Gzip Compression Layer]
                │
                ▼
   [AES-256-GCM Encryption Layer]
                │
                ▼
  [Write Manifest JSON + SHA-256] ──> storage/backups/backup-2026-10-28_090000.aio.gz.enc
```

### Manifest Format (`manifest.json`)
```json
{
  "backup_id": "bkp_65f8a1e4",
  "created_at": "2026-10-28T09:00:00+07:00",
  "database_type": "sqlite",
  "compressed": true,
  "encrypted": true,
  "cipher": "aes-256-gcm",
  "last_migration": "2026_10_28_000032_create_locks_table.sql",
  "sha256_unencrypted": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
  "sha256_package": "d41d8cd98f00b204e9800998ecf8427e",
  "table_counts": {
    "tenants": 2,
    "users": 1,
    "contacts": 15,
    "finance_accounts": 12,
    "items": 45,
    "billing_invoices": 80
  }
}
```

### Interface Definition
```php
namespace Core\Backup;

interface BackupManagerInterface
{
    public function backup(array $options = []): BackupPackage;
    public function restore(string $backupPath, bool $force = false): bool;
    public function verify(): Integrity\IntegrityReport;
    public function heal(): array;
    public function listBackups(): array;
    public function prune(int $keepCount = 10): int;
}
```

---

## 4. Edge Cases & Error Scenarios
- **Restore Terhenti di Tengah Jalan**: Sebelum restore dilakukan, file database aktif saat ini dicadangkan ke `storage/backups/pre_restore_safety_{timestamp}.sqlite`. Jika restorasi gagal, database asli langsung dikembalikan.
- **Kunci Enkripsi Berubah**: Jika `APP_KEY` berbeda saat membongkar arsip cadangan terenkripsi, dekripsi AES-256-GCM melempar `RuntimeException` otentikasi gagal sebelum berkas apapun disentuh.
- **File Backup Rusak / Korup (Bit Rot)**: Checksum SHA-256 yang tercantum dalam manifes diverifikasi terhadap berkas aktual sebelum proses restore dimulai. Jika hash tidak cocok, proses dibatalkan seketika.
- **Tabel Kunci Sedang Digunakan**: Proses hot-backup memanfaatkan SQLite atomic `VACUUM INTO`, yang membaca halaman data secara konsisten tanpa menghentikan request HTTP pengguna.

---

## 5. Test Strategy
- **Unit Tests**:
  - `BackupCreationTest`: Uji pembuatan cadangan unencrypted dan encrypted gzip.
  - `BackupEncryptionSafetyTest`: Uji data terenkripsi tidak dapat dibaca teks terang dan gagal dibuka dengan kunci salah.
  - `BackupRestoreIntegrityTest`: Uji data yang direstore identik dengan data asli sebelum bencana disimulasikan.
  - `DatabaseIntegrityCheckerTest`: Uji deteksi integritas database (PRAGMA integrity_check dan foreign key check).
  - `DatabaseSelfHealerTest`: Uji penyembuhan database, checkpoint WAL, dan reindex.
  - `BackupPruneTest`: Uji pembersihan cadangan lama sesuai batas kuota retensi.
- **CLI Commands Tests**:
  - Uji eksekusi `php aio db:backup`, `db:verify`, `db:heal`, `db:prune-backups`.

---

## 6. Atomic Task Checklist

- [x] **Phase 1: Architecture, Interfaces & Integrity Checker**
  - [x] 1.1 Buat interface `Core\Backup\BackupManagerInterface`.
  - [x] 1.2 Buat driver contract `Core\Backup\Drivers\BackupDriverInterface`.
  - [x] 1.3 Buat DTO `Core\Backup\BackupPackage` dan `Core\Backup\Integrity\IntegrityReport`.
  - [x] 1.4 Buat engine audit `Core\Backup\Integrity\DatabaseIntegrityChecker`.

- [x] **Phase 2: Drivers, Compression & AES-256 Encryption**
  - [x] 2.1 Buat implementasi `Core\Backup\Drivers\SqliteBackupDriver` (`VACUUM INTO`).
  - [x] 2.2 Buat enkripsi/dekripsi stream AES-256-GCM dan kompresi gzip di `BackupPackage`.
  - [x] 2.3 Buat engine pemulihan mandiri `Core\Backup\SelfHealing\DatabaseSelfHealer`.
  - [x] 2.4 Buat implementasi `Core\Backup\BackupManager`.
  - [x] 2.5 Daftarkan binding di `Application.php` dan buat helper global `backup()` di `helpers.php`.

- [x] **Phase 3: CLI Console Diagnostics & Tooling**
  - [x] 3.1 Buat perintah `php aio db:backup` (`core/Console/Commands/DatabaseBackupCommand.php`).
  - [x] 3.2 Buat perintah `php aio db:restore` (`core/Console/Commands/DatabaseRestoreCommand.php`).
  - [x] 3.3 Buat perintah `php aio db:verify` (`core/Console/Commands/DatabaseVerifyCommand.php`).
  - [x] 3.4 Buat perintah `php aio db:heal` (`core/Console/Commands/DatabaseHealCommand.php`).
  - [x] 3.5 Buat perintah `php aio db:prune-backups` (`core/Console/Commands/DatabasePruneBackupsCommand.php`).
  - [x] 3.6 Daftarkan semua perintah di `Application::createCliKernel()`.

- [x] **Phase 4: Unit Testing & Self-Verification (TDD)**
  - [x] 4.1 Tulis unit test komprehensif `tests/Unit/DatabaseBackupTest.php`.
  - [x] 4.2 Tulis unit test self-healing dan verifikasi `tests/Unit/DatabaseSelfHealingTest.php`.
  - [x] 4.3 Pastikan seluruh test lolos 100%.

- [x] **Phase 5: Full Verification, Documentation & Merge**
  - [x] 5.1 Jalankan PHPUnit test suite global.
  - [x] 5.2 Jalankan TypeScript typecheck (`npm run typecheck`) dan Vite build.
  - [x] 5.3 Perbarui status checklist dokumen spec ini menjadi `Completed`.
  - [x] 5.4 Perbarui `specs/README.md` dan `.agents/SESSION_HANDOFF.md`.
  - [x] 5.5 Buat atomic Git commit dan merge branch ke `main`.
