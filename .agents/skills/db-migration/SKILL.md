---
name: db-migration
description: Mengelola perubahan skema database, migrasi terstruktur (up/down), dan integritas data untuk mencegah schema drift dan kehilangan data lokal.
---

# Skill: Database Migration & Schema Discipline

Gunakan skill ini setiap kali ada kebutuhan mengubah skema database (tabel baru, modifikasi kolom, penambahan foreign key, atau index).

## Alur Kerja

### 1. Inspeksi Skema Saat Ini
- Periksa skema database yang aktif saat ini di folder `database/migrations/` dan migrasi yang sudah tercatat.
- Gunakan `Core\Database\Migrator` untuk memeriksa status migrasi yang belum dijalankan.
- Pastikan tidak ada *schema drift* (perbedaan antara file migrasi dan kondisi riil database).

### 2. Desain Perubahan Skema & Portabilitas Multi-DB
- **ANSI SQL Portability First**:
  - Prioritaskan tipe data dan sintaks SQL standar (`INTEGER`, `TEXT`, `REAL`, `TIMESTAMP`, `FOREIGN KEY`) yang kompatibel lintas engine (SQLite, PostgreSQL, MySQL).
  - Hindari fungsi bawaan vendor yang terlalu spesifik pada level tabel bisnis.
- **Backwards Compatibility**:
  - Hindari menghapus kolom langsung jika ada data yang masih aktif digunakan.
  - Untuk kolom non-nullable baru pada tabel yang sudah memiliki data, tentukan nilai *default value* yang aman.
  - Tambahkan indeks pada kolom yang sering dijadikan filter query (`tenant_id`, `status`, foreign key).

### 3. Pembuatan Skrip Migrasi (Up & Down)
- Buat file migrasi di `database/migrations/` dengan format penamaan berurutan: `YYYY_MM_DD_HHMMSS_<deskripsi>.sql`.
- Setiap migrasi wajib memiliki dua penanda arah:
  - `-- @up`: Perubahan skema maju.
  - `-- @down`: Skrip untuk membatalkan perubahan secara bersih (*rollback*).

### 4. Eksekusi & Verifikasi
- Jalankan migrasi di lingkungan lokal (`database.sqlite`) dan verifikasi melalui test suite.
- **Wajib Verifikasi via Test**: Pastikan test suite baru mengeksekusi migrasi menggunakan `\Core\Database\Migrator::runMigrationsFrom(__DIR__ . '/../../database/migrations')`.
- Perbarui dokumen spec (bagian *Data Model & Schema*) agar dokumentasi tetap sinkron dengan skema aktif.
