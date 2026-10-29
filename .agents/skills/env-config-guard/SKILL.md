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
    # Port server aplikasi
    PORT=3000
    # URL database SQLite atau PostgreSQL
    DATABASE_URL="file:./dev.db"
    # Secret key untuk JWT (minimal 32 karakter)
    JWT_SECRET="ganti-dengan-secret-acak"
    ```

### 2. Runtime Schema Validation (Fail-Fast)
- Terapkan validasi variabel environment penting saat bootstrapping kernel aplikasi (fail-fast via `config()` check atau exception terarah jika `APP_KEY` / database DSN kosong).
- Pastikan aplikasi gagal start dengan pesan error yang jelas jika ada variabel wajib yang belum didefinisikan, daripada mengalami runtime crash di tengah jalan.

### 3. Pencegahan Kebocoran (Guardrail)
- Pastikan `.env*` (kecuali `.env.example`) tetap terdaftar di [`.gitignore`](file:///c:/aplikasi/aio/.gitignore).
- Jangan pernah meng-hardcode API key, token rahasia, atau kredensial database di dalam file kode aplikasi atau dokumen spec publik.
