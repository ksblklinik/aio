# SPEC-009: REST API & Bearer API Token System

- **Status**: `Completed`
- **Owner / Author**: aio Framework Architecture Team
- **Tanggal Dibuat**: 2026-10-21
- **Target File / Modul**: `core/Auth/*`, `database/migrations/*`, `app/Modules/Api/*`, `resources/js/Pages/Settings/ApiTokens.tsx`, `tests/Unit/ApiTokenTest.php`

---

## 1. Problem Statement & Context
Dalam sistem SaaS enterprise dan arsitektur modular modern, aplikasi harus dapat diakses secara terprogram (*programmatic access*) tanpa ketergantungan pada cookie session browser. Kebutuhan ini mencakup:
1. Integrasi dengan sistem pihak ketiga melalui webhook dan skrip otomatisasi (e.g. Zapier, Make, CI/CD).
2. Dukungan untuk klien eksternal seperti aplikasi mobile (iOS/Android) atau frontend headless.
3. Kontrol hak akses yang terperinci (*scoped abilities*) yang dapat dicabut kapan saja oleh pemilik workspace tanpa mengganti kata sandi akun pengguna.

---

## 2. Goals & Non-Goals

### Goals
- [x] Menyediakan tabel `api_tokens` dengan penyimpanan aman berbasis hash SHA-256 (`token_hash`), masa kedaluwarsa (`expires_at`), pelacakan waktu pakai terakhir (`last_used_at`), dan hak akses (`abilities`).
- [x] Membangun service `Core\Auth\ApiTokenService` untuk pembuatan token berformat `aio_pat_<32_bytes_hex>`, validasi cepat, pembaruan `last_used_at`, dan pencabutan (*revoke*).
- [x] Membangun middleware `Core\Middleware\AuthenticateApiToken` yang mengamankan rute `/api/v1/*` dengan header standar `Authorization: Bearer <token>`, mengisolasi tenant context secara otomatis, dan mengembalikan respons JSON standar (401 Unauthorized jika tidak valid).
- [x] Menyediakan REST API v1 endpoints contoh:
  - `GET /api/v1/me`: Informasi profil pengguna dan workspace aktif dari token.
  - `GET /api/v1/projects`: Ambil daftar proyek aktif terisolasi sesuai tenant token.
  - `POST /api/v1/projects`: Buat proyek baru secara terprogram via API.
- [x] Membangun UI manajemen API Token di Web Dashboard (`resources/js/Pages/Settings/ApiTokens.tsx`) dengan modal copy-token satu kali (*one-time token reveal*), daftar token aktif, dan tombol revoke.
- [x] Menyediakan perintah CLI `php aio token:create`, `php aio token:list`, dan `php aio token:revoke`.
- [x] Menulis unit & integration tests lengkap di `tests/Unit/ApiTokenTest.php`.

### Non-Goals
- Tidak mencakup OAuth2 authorization code flow penuh (seperti SSO provider Google/GitHub) pada fase ini; fokus adalah Personal Access Token (PAT) per workspace.

---

## 3. Technical Architecture & Design

### Alur Autentikasi REST API
```
[External Client / Webhook]
           │
           │  Header: "Authorization: Bearer aio_pat_..."
           ▼
[Core\Middleware\AuthenticateApiToken]
           │
           ├─► SHA-256 Hash Token ──► Query `api_tokens` (SQLite)
           │
           ├─► Validasi Expiry & Status Aktif
           │
           ├─► Inisialisasi TenantContext & AuthService::setUser()
           ▼
[REST API Controller: /api/v1/*] ──► Return JSON Response
```

### Database Schema (`api_tokens`)
```sql
CREATE TABLE IF NOT EXISTS api_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    abilities TEXT DEFAULT '*',
    last_used_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_api_tokens_hash ON api_tokens(token_hash);
CREATE INDEX IF NOT EXISTS idx_api_tokens_tenant ON api_tokens(tenant_id);
```

---

## 4. Atomic Task Checklist

- [x] **Phase 1: Database Migration & Core Token Service**
  - [x] 1.1 Buat migration `database/migrations/2026_10_21_000006_create_api_tokens_table.sql`.
  - [x] 1.2 Buat service `Core\Auth\ApiTokenService` (hashing, generate, validate, revoke, list).
  - [x] 1.3 Daftarkan `ApiTokenService` ke Container di `public/index.php` dan `aio`.
  - [x] 1.4 Tulis unit tests di `tests/Unit/ApiTokenTest.php`.

- [x] **Phase 2: API Middleware & REST Endpoints (v1)**
  - [x] 2.1 Buat middleware `Core\Middleware\AuthenticateApiToken`.
  - [x] 2.2 Buat REST controller `App\Modules\Api\Controllers\V1\ApiController` (`/api/v1/me`, `/api/v1/projects`).
  - [x] 2.3 Daftarkan rute API di `routes/web.php`.
  - [x] 2.4 Uji integrasi endpoint API dengan token Bearer via PHPUnit.

- [x] **Phase 3: Web Dashboard UI & CLI Tooling**
  - [x] 3.1 Buat controller `App\Modules\Settings\Controllers\ApiTokenController` (`GET/POST /settings/api-tokens`).
  - [x] 3.2 Buat halaman React 19 `resources/js/Pages/Settings/ApiTokens.tsx` dengan modal satu kali salin token dan tabel daftar token.
  - [x] 3.3 Buat CLI commands `TokenCreateCommand`, `TokenListCommand`, dan `TokenRevokeCommand` (`php aio token:*`).
  - [x] 3.4 Daftarkan menu "API & Integrasi" di sidebar `AppLayout.tsx`.

- [x] **Phase 4: Self-Verification, Full Test Suite & Git Discipline**
  - [x] 4.1 Jalankan `vendor/bin/phpunit` dan `npm run build`.
  - [x] 4.2 Uji eksekusi langsung perintah CLI token via terminal.
  - [x] 4.3 Jalankan `./scripts/verify.ps1`.
  - [x] 4.4 Update `specs/009-api-token-system.md`, `specs/README.md`, dan `.agents/SESSION_HANDOFF.md`.
  - [x] 4.5 Atomic Git commit dan merge ke `main`.
