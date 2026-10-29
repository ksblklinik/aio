# SPEC-016: Template Packaging & Release

- **Status**: `Draft`
- **Owner / Author**: Antigravity & User
- **Tanggal Dibuat**: 2026-10-26
- **Target File / Modul**: `README.md`, `docs/`, `scripts/`, `.github/`

---

## 1. Problem Statement & Context
Setelah SPEC-013, SPEC-014, dan SPEC-015 rampung, repositori telah memiliki fondasi platform yang kedap dan serbaguna. Langkah terakhir adalah mengemas repositori menjadi template siap pakai (*starter kit* / *boilerplate*) sehingga saat di-klon atau digunakan via GitHub Template, pengembang baru dapat langsung menggunakannya dalam hitungan menit tanpa sisa data kotor atau konfigurasi yang rusak.

## 2. Goals & Non-Goals
### Goals
- Mengabstraksi seluruh elemen branding (`APP_NAME`, logo, copyright) ke file konfigurasi dan env.
- Membuat wizard instalasi CLI `php aio app:install` (generate APP_KEY, copy `.env`, jalankan migrasi, buat superadmin pertama).
- Melakukan sanitasi database: memastikan SQLite bersih dari data dummy pengujian dan hanya berisi skema/seeder dasar.
- Memastikan modul bawaan `Projects` direfaktor ke standar kontrak baru sebagai modul percontohan (*reference implementation*).
- Menulis dokumentasi lengkap: `README.md` (quickstart) dan `docs/MODULE_DEVELOPMENT.md` (panduan membuat modul bisnis baru).
- Merilis Git Tag `v1.0.0-core` dan panduan aktivasi GitHub Template Repository.

### Non-Goals
- Tidak mempublikasikan paket ke Packagist (ini adalah application boilerplate, bukan library standalone).

---

## 3. Atomic Task Checklist

- [x] **Phase 1: Dynamic Branding & Configuration Cleanup**
  - [x] 1.1 Hapus teks statis `aio SaaS` dari backend dan layout React; ganti dengan `config('app.name')` via Inertia shared props.
  - [x] 1.2 Dukung konfigurasi logo dan favicon kustom via asset helper / config.

- [x] **Phase 2: One-Click App Installer CLI**
  - [x] 2.1 Buat sub-perintah `php aio app:install` yang memandu setup `.env`, generate key, migrasi, dan seed interaktif.
  - [x] 2.2 Verifikasi kompatibilitas perintah instalasi di Windows (PowerShell) dan Linux (Bash).

- [ ] **Phase 3: Reference Module Migration & Data Sanitization**
  - [ ] 3.1 Refactor modul bawaan `Projects` agar menggunakan `ModuleProvider`, migrasi lokal modul, dan permission gate.
  - [ ] 3.2 Bersihkan database lokal dari data pengujian manual; sediakan seeder bersih di `database/seeders/`.
  - [ ] 3.3 Pastikan `.gitignore` secara ketat mengabaikan file database sqlite lokal dan log aktif.

- [ ] **Phase 4: Developer Documentation & Guidelines**
  - [ ] 4.1 Perbarui `README.md` dengan panduan instalasi 3 menit dari template.
  - [ ] 4.2 Tulis `docs/MODULE_DEVELOPMENT.md` yang memandu developer membuat modul bisnis baru dari nol dengan `php aio make:module`.
  - [ ] 4.3 Tambahkan arsitektur diagram di dokumentasi.

- [ ] **Phase 5: Final Quality Gate, Tagging & GitHub Template Release**
  - [ ] 5.1 Jalankan full verification gate (`./scripts/verify.ps1`, `phpunit`, `tsc --noEmit`, asset build).
  - [ ] 5.2 Berikan git tag `v1.0.0-core`.
  - [ ] 5.3 Buat panduan konfigurasi repo GitHub baru dengan flag *Template repository*.
