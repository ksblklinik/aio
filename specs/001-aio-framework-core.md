# SPEC-001: aio Full-Stack SaaS Framework Architecture

- **Status**: `Draft`
- **Owner / Author**: Antigravity & User
- **Tanggal Dibuat**: 2026-10-21
- **Target File / Modul**: `core/`, `src/`, `config/`, `routes/`, `resources/`

---

## 1. Problem Statement & Context
Membangun aplikasi web dan SaaS modern dari nol seringkali memaksa developer solo atau tim lincah mengelola dua codebase yang terpisah secara agresif (backend REST/GraphQL API + frontend SPA terpisah), mengakibatkan overhead sinkronisasi kontrak data, manajemen token JWT ganda, state caching yang rumit, dan kerentanan keamanan XSS.

**aio** dirancang sebagai **Unified Full-Stack SaaS Framework** berbasis PHP 8.3+ dan React 19 (TypeScript) via protokol **Inertia.js**. Ini memberikan kenyamanan produktivitas monolitik (routing dan auth di backend) dengan keunggulan interaktivitas Single-Page Application (SPA) modern tanpa menulis REST boilerplate manual, dilengkapi *out-of-the-box tenant scoping* untuk SaaS.

---

## 2. Goals & Non-Goals

### Goals
- [ ] Menyediakan **PHP HTTP Kernel** berbasis standar PSR (PSR-7 HTTP message, PSR-15 middleware, PSR-11 container) yang kompatibel dengan PHP CLI/FPM dan **FrankenPHP worker mode**.
- [ ] Mengimplementasikan **Inertia.js Protocol Adapter** di PHP: render view React dengan props JSON reaktif, menangani partial reloads, redirect, dan shared props (auth user, flash messages, tenant context).
- [ ] Membangun **Vite + React 19 + TypeScript** frontend bundle dengan integrasi asset tag otomatis di PHP.
- [ ] Menyediakan **Tenant Scoping Layer** untuk SaaS (single database dengan isolasi `tenant_id` otomatis).
- [ ] Sistem autentikasi berbasis **Secure HTTP-only Cookies + CSRF Protection** (bebas risiko XSS localStorage).
- [ ] Integrasi penuh dengan gerbang keamanan [scripts/verify.ps1](file:///c:/aplikasi/aio/scripts/verify.ps1) dan dev server [scripts/dev.ps1](file:///c:/aplikasi/aio/scripts/dev.ps1).

### Non-Goals
- Tidak mencakup multi-database tenancy per customer pada tahap 001 (difokuskan pada single-db tenant scoping yang efisien).
- Tidak mencakup sistem billing pihak ketiga (Stripe/Midtrans) di iterasi 001 (akan menjadi spec tersendiri).
- Tidak membuat generator mobile app native di fase fondasi ini.

---

## 3. Technical Architecture & Design

### Alur Request & State Bridge
```
[Browser Client: React 19 + Inertia Client]
       │
       │ (1. Initial HTML Request / 2. X-Inertia AJAX Request)
       ▼
[aio HTTP Kernel: PHP 8.3 / FrankenPHP]
   ├── [Middleware: Session & CSRF Guard]
   ├── [Middleware: Tenant Resolver] (subdomain atau session header)
   ├── [Router: Match Route -> Controller Action]
   └── [Inertia Response Factory]
          │
          ├── (Initial Visit): Return HTML page wrapper + `<div id="app" data-page="...">`
          └── (Inertia Visit): Return JSON payload `{ component, props, url, version }`
```

### Struktur Direktori yang Direncanakan
```
aio/
├── app/
│   ├── Controllers/         # Controller aplikasi (misal: DashboardController)
│   ├── Middleware/          # PSR-15 Middlewares (Auth, CSRF, TenantContext)
│   ├── Models/              # Data model & entity
│   └── Providers/           # Service providers & boots
├── config/
│   ├── app.php              # Konfigurasi aplikasi
│   └── database.php         # Konfigurasi koneksi SQLite / PostgreSQL / MySQL
├── core/
│   ├── Container.php        # PSR-11 DI Container
│   ├── Database.php         # PDO Database Runner & Tenant Query Scoper
│   ├── Http/                # PSR-7 / Request / Response / Kernel
│   ├── Inertia/             # Inertia.js PHP Adapter
│   └── Router.php           # Routing Engine
├── public/
│   └── index.php            # Single entry point aplikasi
├── resources/
│   ├── css/
│   │   └── app.css          # CSS / Tailwind setup
│   ├── js/
│   │   ├── app.tsx          # Inertia React entry point
│   │   └── Pages/           # Komponen halaman (React TSX)
│   └── views/
│       └── app.php          # HTML base template wrapper
├── routes/
│   └── web.php              # Definisi route aplikasi
├── scripts/                 # Otomatisasi dev.ps1, verify.ps1, map.ps1
├── specs/                   # Spesifikasi teknis
├── composer.json            # Dependensi backend PHP
├── package.json             # Dependensi frontend UI (React, Vite, Inertia)
├── tsconfig.json            # Konfigurasi TypeScript
└── vite.config.ts           # Konfigurasi bundling Vite
```

---

## 4. Edge Cases & Error Scenarios
- **Inertia Version Drift**: Jika aset frontend diperbarui saat user sedang membuka aplikasi, backend mereturn status `409 Conflict` dengan header `X-Inertia-Location` untuk memicu hard refresh ke aset terbaru.
- **Tenant Leakage**: Query data tanpa filter `tenant_id` dicegah melalui wrapper query atau tenant scope enforcement.
- **CSRF Token Mismatch**: Request mutasi (POST/PUT/DELETE) tanpa token CSRF yang valid ditolak dengan HTTP 419 / 403.
- **Dev vs Prod Asset Loading**: Mode dev menyambung ke Vite HMR server (`http://localhost:5173`), sedangkan mode prod menyajikan manifest aset dari `public/build/`.

---

## 5. Test Strategy
- **Backend Tests (Pest / PHPUnit)**:
  - Routing test: route dispatching dan parameter binding.
  - Inertia response test: verifikasi header `X-Inertia` dan struktur JSON props.
  - Tenant scope test: verifikasi query tenant terisolasi.
- **Frontend / Integration Tests**:
  - Type-checking via `tsc --noEmit`.
  - Smoke test: page mount dan rendering props.
- **Automated Verification**:
  - Jalankan `./scripts/verify.ps1` untuk memastikan seluruh gerbang lolos tanpa error.

---

## 6. Atomic Task Checklist

- [x] **Phase 1: Project Setup & Package Configurations**
  - [x] 1.1 Inisialisasi `composer.json` (PHP 8.3+, PSR-7/11/15, autoload `App\\` dan `Core\\`).
  - [x] 1.2 Inisialisasi `package.json` (React 19, @inertiajs/react, Vite, TypeScript, Tailwind).
  - [x] 1.3 Konfigurasi `vite.config.ts` dan `tsconfig.json`.
  - [x] 1.4 Perbarui script dev/verify untuk mendukung ekosistem PHP + Node.

- [x] **Phase 2: Core HTTP Engine & Routing**
  - [x] 2.1 Buat `Core\Container` (PSR-11 DI container minimalis).
  - [x] 2.2 Buat `Core\Http\Request` dan `Core\Http\Response`.
  - [x] 2.3 Buat `Core\Router` dengan dukungan route parameters dan middleware pipeline.
  - [x] 2.4 Buat middleware dasar: Session, CSRF Protection, dan Error Handling.

- [x] **Phase 3: Inertia.js Bridge & View Renderer**
  - [x] 3.1 Implementasikan `Core\Inertia\Inertia` response factory (render, share, version).
  - [x] 3.2 Buat template HTML root wrapper (`resources/views/app.php`).
  - [x] 3.3 Buat helper view rendering asset Vite (`vite_asset()` untuk HMR & manifest prod).

- [x] **Phase 4: Database & Multi-Tenant Scoping**
  - [x] 4.1 Buat `Core\Database` (koneksi PDO SQLite/PostgreSQL/MySQL).
  - [x] 4.2 Buat mekanisme Tenant Context & Scoper (`tenant_id` scope enforcement).
  - [x] 4.3 Buat sistem migrasi skema tabel sederhana untuk inisialisasi database.

- [ ] **Phase 5: Frontend React 19 UI & Dashboard Starter**
  - [ ] 5.1 Buat entry point React Inertia (`resources/js/app.tsx`).
  - [ ] 5.2 Buat layout dasar dashboard SaaS (`resources/js/Layouts/AppLayout.tsx`).
  - [ ] 5.3 Buat halaman starter: Landing Page & Dashboard SaaS (`resources/js/Pages/Dashboard.tsx`).
  - [ ] 5.4 Konfigurasi styling Tailwind CSS dengan estetika visual modern.

- [ ] **Phase 6: Verification, Test Suites & Dev Tooling**
  - [ ] 6.1 Tulis unit tests untuk Router, Container, dan Inertia Bridge.
  - [ ] 6.2 Uji end-to-end dev server via `scripts/dev.ps1`.
  - [ ] 6.3 Jalankan dan validasi via `scripts/verify.ps1`.
