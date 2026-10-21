# SPEC-007: Unified CLI Console Tooling (php aio)

- **Status**: `Completed`
- **Owner / Author**: aio Framework Architecture Team
- **Tanggal Dibuat**: 2026-10-21
- **Target File / Modul**: `aio`, `core/Console/*`, `tests/Unit/ConsoleTest.php`

---

## 1. Problem Statement & Context
Sebelumnya, perintah otomasi, inisialisasi basis data, dan tugas-tugas administratif dijalankan melalui script ad-hoc terpisah (misal: `scripts/init_db.php`, `scripts/verify.ps1`, atau script PHP inline). Hal ini menimbulkan kelemahan:
1. Tidak ada antarmuka baris perintah tunggal (*single CLI entry point*) seperti `php artisan` di Laravel atau `bin/console` di Symfony.
2. Pengembang/admin kesulitan mengingat argumen skrip yang tidak memiliki dokumentasi bawaan (`php aio list` / `--help`).
3. Pembuatan modul baru atau akun superadmin di server produksi/staging memerlukan akses manual atau antarmuka web.

---

## 2. Goals & Non-Goals

### Goals
- [x] Membuat file executable console root **`aio`** (`php aio`).
- [x] Membangun `Core\Console\Kernel` dan `Core\Console\Command` dengan parser argumen & opsi CLI, serta pewarnaan ANSI terminal.
- [x] Mengimplementasikan perintah-perintah inti:
  - `list` / `help`: Menampilkan sitemap seluruh perintah yang tersedia.
  - `migrate`: Menjalankan seluruh file migrasi skema SQL database.
  - `make:module <name>`: Membuat modul baru secara otomatis via scaffolding.
  - `user:create-superadmin`: Membuat atau mempromosikan akun superadmin (interaktif atau via flag).
  - `module:list`: Menampilkan tabel seluruh modul yang terpasang, status, dan kategorinya.
  - `route:list`: Menampilkan tabel seluruh endpoint URI dan controller pemroses.
  - `tenant:list`: Menampilkan ringkasan workspace tenant terdaftar.
- [x] Menyediakan unit test terisolasi (`tests/Unit/ConsoleTest.php`) untuk memastikan seluruh command mengembalikan exit code yang benar.

### Non-Goals
- Tidak memerlukan dependensi Composer eksternal (murni native PHP 8.3 CLI).

---

## 3. Technical Architecture & Design

### Alur Eksekusi Console
```
[Terminal: php aio <command> [args] [--options]]
                     │
                     ▼
                 [root: aio]
                     │
                     ▼
          [Core\Console\Kernel]
         (Auto-discover & Route)
                     │
         ┌───────────┼───────────┐
         ▼           ▼           ▼
     [Migrate]  [MakeModule]  [RouteList] ...
```

---

## 4. Atomic Task Checklist

- [x] **Phase 1: Console Kernel & Base Command Engine**
  - [x] 1.1 Buat class abstrak `Core\Console\Command` lengkap dengan helper output (`info`, `success`, `error`, `warn`, `table`).
  - [x] 1.2 Buat class `Core\Console\Kernel` untuk registrasi command, parsing argumen CLI, dan penanganan exception.
  - [x] 1.3 Buat entry point executable script `aio` di root direktori.
  - [x] 1.4 Tulis unit test di `tests/Unit/ConsoleTest.php` untuk memvalidasi kernel dan runner.

- [x] **Phase 2: Core Command Implementations**
  - [x] 2.1 Buat command `Core\Console\Commands\ListCommand` (`php aio list`).
  - [x] 2.2 Buat command `Core\Console\Commands\MigrateCommand` (`php aio migrate`).
  - [x] 2.3 Buat command `Core\Console\Commands\MakeModuleCommand` (`php aio make:module <name> [--category=]`).
  - [x] 2.4 Buat command `Core\Console\Commands\CreateSuperadminCommand` (`php aio user:create-superadmin`).
  - [x] 2.5 Buat command `Core\Console\Commands\ModuleListCommand` (`php aio module:list`).
  - [x] 2.6 Buat command `Core\Console\Commands\RouteListCommand` (`php aio route:list`).
  - [x] 2.7 Buat command `Core\Console\Commands\TenantListCommand` (`php aio tenant:list`).

- [x] **Phase 3: Verification & Integration**
  - [x] 3.1 Jalankan seluruh test suite `vendor/bin/phpunit`.
  - [x] 3.2 Uji eksekusi langsung setiap perintah via terminal: `php aio list`, `php aio module:list`, dll.
  - [x] 3.3 Perbarui `specs/README.md` dan `.agents/SESSION_HANDOFF.md`.
  - [x] 3.4 Atomic Git commit.
