# Session Handoff State

Dokumen ini adalah *Single Source of Truth* untuk transfer konteks antar-sesi AI Coding di repositori **aio**. Dokumen ini dibaca secara otomatis setiap mengawali percakapan baru dan diperbarui setiap mengakhiri sesi.

---

## 📌 Status Terakhir Sesi (Last Updated: 2026-10-22 14:52 WIB)

* **Conversation ID Terakhir**: `c5b716c1-4cc9-4cc2-be5a-29df4261d938`
* **Active Git Branch**: `main` (Semua feature branch bersih, SPEC-012 sudah di-merge dengan flag `--no-ff`)
* **Active Spec**: [`specs/012-core-interoperability-and-sanitization.md`](file:///c:/aplikasi/aio/specs/012-core-interoperability-and-sanitization.md)
* **Spec Status**: `Completed`
* **Test Suite Status**: **156 tests (838 assertions) PASS 100%**, `tsc --noEmit` clean, bundle build clean.
* **Database Migrations**: 8 migrasi telah diterapkan di `database/aio.sqlite`.
* **Sanitasi Modul**: Seluruh 8 folder di `app/Modules/` (`Auth`, `Workspaces`, `Projects`, `Modules`, `Activity`, `Notification`, `Settings`, `Api`) kini 100% patuh standar manifesto `module.json` dan rute modular mandiri `routes.php`.
* **Aturan Konstitusi Baru**: [`.agents/rules/08-zero-infrastructure-leakage.md`](file:///c:/aplikasi/aio/.agents/rules/08-zero-infrastructure-leakage.md) & Guardrail 6 di `AGENTS.md` (larangan mutlak menambal infrastruktur secara lokal di modul bisnis).

---

## 🛡️ Catatan Mitigasi Masalah Layar Blank (Post-Mortem Guardrails):
- Telah dipasang guard otomatis di `public/index.php` untuk melayani file statis (`cli-server`).
- Telah dipasang unit test `ServerEnvironmentTest.php` untuk mencegah regresi bypass file statis.
- Skrip `./scripts/verify.ps1` secara otomatis memeriksa integritas bundle assets (`manifest.json`) dan proteksi server sebelum commit diizinkan.

---

## 🚀 Rangkuman Arsitektur Framework aio (SPEC-001 s/d SPEC-012)

1. **SPEC-001 Core Engine**: PSR-11 Container auto-wiring, Router parameter regex, SQLite Multi-Tenant Connection & Scope, Inertia React 19 bridge.
2. **SPEC-002 Auth & RBAC**: Session Auth, Multi-Workspace selector, roles (`owner`, `admin`, `member`), dan Project tracker.
3. **SPEC-003 Dynamic Module Manager**: Plugin registry di SQLite, modul on/off per tenant, guard dependensi, auto-scaffold modul.
4. **SPEC-004 Platform Superadmin**: Akses global governance, audit semua tenant, emergency killswitch modul platform-wide.
5. **SPEC-005 Universal Taxonomy**: Registry 8 kategori standar industri (`core`, `commerce`, `finance`, `operations`, `crm`, `hr`, `marketing`, `analytics`).
6. **SPEC-006 Validation Engine**: `Validator`, trait `ValidatesRequests`, dan Session flash `ErrorBag` untuk error per-input form.
7. **SPEC-007 CLI Console Tooling**: Binary `php aio` dengan 17 sub-perintah (routes, modules, migrations, users, tokens, webhooks, queue).
8. **SPEC-008 Audit Logging**: `AuditLogger` otomatis merekam tenant, user, IP, action, entity, dan metadata diff di `/settings/activity-logs`.
9. **SPEC-009 REST API & Token System**: `ApiTokenService`, Bearer Token Middleware, hashing SHA-256, expiration, abilities wildcard di `/settings/api-tokens`.
10. **SPEC-010 Notification Engine**: In-App Alerts, bell popover dropdown di topbar, polling badge unread, dan management center di `/notifications`.
11. **SPEC-011 Database Queue & Webhook Dispatcher**: Asynchronous job queue (`jobs`, `failed_jobs`), worker daemon `php aio queue:work`, outbound HMAC-SHA256 webhooks, auto-retry, delivery inspector di `/settings/webhooks`.
12. **SPEC-012 Core Interoperability & Module Sanitization**:
    - **Domain Event Engine**: `Core\Events\Event`, `EventDispatcher`, `ListenerInterface`, sync & async listeners via Queue, auto-bridge ke Outbound Webhooks, dan helper `event()`.
    - **Module Directory Sanitization**: Seluruh folder di `app/Modules/` memiliki manifesto `module.json` dan rute terisolasi `routes.php` yang dimuat secara dinamis oleh router utama.
    - **Rule 08 Zero Infrastructure Leakage**: Garansi arsitektur bahwa modul bisnis tidak akan pernah menambal kapabilitas platform sendiri.

---

## 🎯 Instruksi Untuk Sesi Berikutnya (Next Immediate Steps)

Fondasi arsitektur dan interoperabilitas modular kini telah 100% matang, bersih, dan terkunci. Sistem siap menerima pembuatan modul bisnis domain:

- **Pilihan 1: Modul CRM (Customer Relationship Management) — Direkomendasikan**
  - Buat `specs/013-crm-module.md`.
  - Manajemen Kontak (*Contacts*) & Perusahaan (*Companies*).
  - Papan Pipa Prospek / Kesepakatan Penjualan (*Deals Kanban Pipeline*) dengan status stage.
  - Memanfaatkan `event(new DealCreated(...))` yang otomatis memicu webhook outbound `crm.deal.created` dan in-app notification tanpa tight-coupling!
- **Pilihan 2: Modul Invoicing & Billing**
  - Buat `specs/013-invoicing-module.md`.
  - Pembuatan faktur/invoice, item transaksi, subtotal/tax/discount kalkulator, status bayar, dan export PDF.
- **Pilihan 3: Modul Inventory & Stock Control**
  - Buat `specs/013-inventory-module.md`.
  - Manajemen katalog SKU produk, log pergerakan stok (in/out/adjustment), dan peringatan stok menipis.

---

## 📋 Catatan Penting & Quick Commands
* **Jalankan Dev Server**: `powershell -ExecutionPolicy Bypass -File .\scripts\dev.ps1` (menjalankan PHP server di port 8000 & Vite di port 5173).
* **Jalankan Background Queue Worker**: `php aio queue:work`
* **Jalankan Verification Gate**: `powershell -ExecutionPolicy Bypass -File .\scripts\verify.ps1`.
* **Eksekusi Test Suite**: `vendor/bin/phpunit`
