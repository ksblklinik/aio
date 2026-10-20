---
name: db-migration
description: Mengelola perubahan skema database, migrasi terstruktur (up/down), dan integritas data untuk mencegah schema drift dan kehilangan data lokal.
---

# Skill: Database Migration & Schema Discipline

Gunakan skill ini setiap kali ada kebutuhan mengubah skema database (tabel baru, modifikasi kolom, penambahan foreign key, atau index).

## Alur Kerja

### 1. Inspeksi Skema Saat Ini
- Periksa skema database yang aktif saat ini:
  - Melalui file skema (misal: `schema.prisma`, `schema.ts` di Drizzle, file SQL migrasi sebelumnya).
  - Atau melalui server MCP database jika terhubung (`sqlite-db`, `postgres-db`).
- Pastikan tidak ada *schema drift* (perbedaan antara definisi kode dan kondisi riil database lokal).

### 2. Desain Perubahan Skema
- Pastikan perubahan mendukung backwards compatibility jika memungkinkan:
  - Hindari menghapus kolom langsung jika ada data yang masih aktif digunakan.
  - Untuk kolom non-nullable baru pada tabel yang sudah memiliki data, tentukan nilai *default value* yang aman.
  - Tambahkan indeks pada kolom yang sering dijadikan filter query atau foreign key.

### 3. Pembuatan Skrip Migrasi (Up & Down)
- Buat file migrasi dengan penomoran urut dan nama deskriptif (misal: `0002_create_users_table.sql` atau via ORM migration tool).
- Setiap migrasi wajib memiliki dua arah:
  - **Up Migration**: Perubahan skema maju.
  - **Down Migration / Rollback**: Skrip untuk membatalkan perubahan secara bersih jika terjadi kegagalan.

### 4. Eksekusi & Verifikasi
- Jalankan migrasi di lingkungan lokal atau test environment.
- Verifikasi skema baru berhasil terbentuk (query `PRAGMA table_info` di SQLite atau `\d` di Postgres).
- Perbarui dokumen spec (bagian *Data Model & Schema*) agar tetap sinkron.
