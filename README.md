# aio — Modern Multi-Tenant SaaS Boilerplate & Application Framework

**aio** adalah boilerplate & framework aplikasi web multi-tenant modern berbasis PHP 8.3+, React 19, Inertia.js, dan SQLite. Dirancang khusus untuk arsitektur SaaS otonom, *offline-first development*, performa tinggi, serta kepatuhan ketat terhadap protokol **Spec-Driven Development**.

---

## 🚀 Memulai Cepat (3-Minute Quickstart)

### 1. Prasyarat Sistem
- **PHP**: >= 8.3 dengan ekstensi `pdo_sqlite`, `curl`, `mbstring`, `fileinfo`.
- **Node.js**: >= 20.x & **npm**.
- **Composer**: >= 2.x.

### 2. Instalasi dari Template

```bash
# 1. Klon repositori atau gunakan tombol "Use this template" di GitHub
git clone https://github.com/your-org/aio.git my-saas-app
cd my-saas-app

# 2. Pasang dependensi PHP dan Node.js
composer install
npm install

# 3. Jalankan wizard instalasi satu-perintah
php aio app:install
```

Wizard `app:install` akan otomatis:
1. Menyalin `.env.example` menjadi `.env`.
2. Menghasilkan kunci enkripsi aplikasi (`APP_KEY`).
3. Menjalankan seluruh migrasi database Core dan modul bawaan.
4. Membuat akun administrator pertama Anda secara interaktif.

### 3. Menjalankan Server Pengembangan

Di Windows (PowerShell):
```powershell
powershell -ExecutionPolicy Bypass -File ./scripts/dev.ps1
```

Atau secara manual di dua terminal terpisah:
```bash
# Terminal 1: Vite HMR Frontend
npm run dev

# Terminal 2: PHP Development Server
php -S localhost:8000 -t public
```

Buka peramban Anda di: `http://localhost:8000`

---

## 🏛️ Diagram Arsitektur Sistem

```
┌────────────────────────────────────────────────────────────────────────┐
│                        PRESENTATION LAYER                              │
│         React 19 + TypeScript + Inertia.js + Semantic Design Tokens    │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │ HTTP / JSON
┌───────────────────────────────────▼────────────────────────────────────┐
│                         CORE GATEWAY & ROUTING                         │
│     Router, Middleware Pipeline (Auth, Csrf, RateLimit, TenantScope)    │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
       ┌────────────────────────────┴─────────────────────────────┐
       ▼                                                          ▼
┌──────────────────────────────────────┐  ┌──────────────────────────────┐
│       AUTONOMOUS BUSINESS MODULES    │  │       CORE SERVICES          │
│       (app/Modules/*)                │  │       (core/*)               │
│  - Projects & Tasks (Reference)      │  │  - Tenancy Isolation Scope   │
│  - Billing & Invoicing               │  │  - Dynamic RBAC & Permissions│
│  - Inventory & Multi-Warehouse       │  │  - Transactional Outbox      │
│  - Finance & Double-Entry Ledger     │  │  - Async Queue & Webhooks    │
│  - Facility & Resource Booking       │  │  - Privacy & Crypto-Shredding│
│  - Schedule & Appointments           │  │  - Performance Sentinel      │
│  - Contacts & Terminology            │  │  - Disaster Recovery Backup  │
└──────────────────────────────────────┘  └──────────────────────────────┘
                                    │
┌───────────────────────────────────▼────────────────────────────────────┐
│                          PERSISTENCE LAYER                             │
│     SQLite (WAL Mode, High Concurrency, Foreign Keys, Automatic VAC)   │
└────────────────────────────────────────────────────────────────────────┘
```

---

## 📦 Fitur-Fitur Utama

1. **Multi-Tenancy By Design**: Isolasi data ketat berbasis sub-organisasi / workspace dengan `TenantScope` otomatis tanpa celah kebocoran antar tenant.
2. **Zero Infrastructure Leakage**: Seluruh kapabilitas platform (queue worker, event bus, webhook engine, telemetry profiler) terisolasi di folder `core/`, menjaga modul bisnis tetap bersih dan modular.
3. **Dynamic RBAC & Granular Permissions**: Manajemen hak akses tingkat peran (`owner`, `admin`, `member`) dan izin granular (`can('projects.create')`) yang dapat dikonfigurasi saat runtime.
4. **Privacy & Data Shredding**: Fitur kepatuhan privasi (GDPR/UU PDP) bawaan: anonimisasi tenant, export portabilitas data deterministik, serta *cryptographic instant shredding*.
5. **Transactional Outbox & Robust Queues**: Pengiriman webhook dan event asinkron yang tahan banting tanpa risiko kehilangan data (*guaranteed delivery*).
6. **Inertia.js Monolith Experience**: Frontend React modern dengan kenyamanan monolith tanpa kerepotan mengelola API REST/GraphQL terpisah.

---

## 🛠️ CLI Toolkit (`php aio`)

Aplikasi dilengkapi dengan CLI binary serbaguna:

| Perintah | Deskripsi |
| :--- | :--- |
| `php aio app:install` | Setup satu perintah (`.env`, `APP_KEY`, migrasi, seed admin) |
| `php aio make:module <Name>` | Scaffold modul bisnis baru dengan struktur otonom |
| `php aio migrate` | Menjalankan seluruh migrasi Core dan modul aktif |
| `php aio migrate:rollback` | Rollback batch migrasi terakhir |
| `php aio queue:work` | Menjalankan worker pemrosesan queue latar belakang |
| `php aio schedule:run` | Mengeksekusi task scheduler cron berkala |
| `php aio backup:run` | Membuat backup terenkripsi database lokal |
| `php aio route:list` | Menampilkan seluruh daftar rute HTTP yang terdaftar |

Untuk panduan lengkap membuat modul kustom, baca: [docs/MODULE_DEVELOPMENT.md](docs/MODULE_DEVELOPMENT.md).

---

## 🛡️ Protokol Pengembangan (Spec-Driven Development)

Repositori ini menerapkan protokol pengembangan berbasis spesifikasi dan disiplin Git pre-commit otomatis:

```
[Phase 1: Clarify & Grill] ──> [Phase 2: Spec & Plan] ──> [Phase 3: Atomic TDD] ──> [Phase 4: Verify & Review]
```

Jalankan gerbang verifikasi mandiri kapan saja sebelum membuat commit:

```powershell
powershell -ExecutionPolicy Bypass -File ./scripts/verify.ps1
```

Pemeriksaan mencakup:
1. **Security Scan**: Pemindaian token/secret mentah dan file sensitif.
2. **Spec Sync**: Memastikan progress pekerjaan sinkron dengan dokumen di folder `specs/`.
3. **Automated Tests**: Rangkaian 540+ pengujian unit PHPUnit dan typecheck TypeScript.
4. **Git Hygiene**: Memvalidasi kebersihan working tree dan ketiadaan artefak kotor.

---

## 📄 Lisensi & Distribusi

Template repositori ini dirilis di bawah lisensi [MIT License](LICENSE). Anda bebas menggunakannya sebagai fondasi produk komersial maupun open-source.
