---
name: env-config-guard
description: Mengelola variabel lingkungan, sinkronisasi .env.example, dan validasi konfigurasi saat runtime untuk mencegah runtime crash dan kebocoran kredensial.
---

# Skill: Environment Configuration Guard

Gunakan skill ini ketika menambahkan variabel konfigurasi baru, mengintegrasikan API pihak ketiga, atau menyusun setup deployment.

## Alur Kerja

### 1. Sinkronisasi `.env.example`
- Setiap kali variabel baru ditambahkan ke `.env` lokal:
  - Wajib langsung didaftarkan ke `.env.example` dengan nilai dummy/placeholder yang aman.
  - Berikan komentar penjelasan format yang diharapkan:
    ```bash
    # Application Key (wajib diisi, minimal 32 karakter acak / base64)
    APP_KEY=base64:your-generated-key
    # Database Configuration (SQLite default)
    DB_CONNECTION=sqlite
    DB_PATH=database.sqlite
    DB_FOREIGN_KEYS=true
    ```

### 2. Runtime Schema Validation (Fail-Fast) & Centralized Access
- **Centralized Config Access**: Modul bisnis **dilarang** memanggil `$_ENV` atau `getenv()` secara langsung di dalam service/controller. Seluruh konfigurasi wajib diakses melalui helper terpusat `config('key')` atau Service Provider untuk menjamin testability dan isolasi runtime.
- **Fail-Fast Validation**: Terapkan validasi variabel environment penting saat bootstrapping kernel aplikasi (fail-fast via `config()` check atau exception terarah jika `APP_KEY` / database DSN kosong).
- Pastikan aplikasi gagal start dengan pesan error yang jelas jika ada variabel wajib yang belum didefinisikan, daripada mengalami runtime crash di tengah jalan.

### 3. Pencegahan Kebocoran (Guardrail)
- Pastikan `.env*` (kecuali `.env.example`) tetap terdaftar di [`.gitignore`](file:///c:/aplikasi/aio/.gitignore).
- Jangan pernah meng-hardcode API key, token rahasia, atau kredensial database di dalam file kode aplikasi atau dokumen spec publik.
