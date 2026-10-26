# SPEC-015: Universal Platform Services

- **Status**: `Completed`
- **Owner / Author**: Antigravity & User
- **Tanggal Dibuat**: 2026-10-26
- **Target File / Modul**: `core/Storage/`, `core/Settings/`, `core/Sequence/`, `core/Schedule/`, `core/Mail/`

---

## 1. Problem Statement & Context
Setiap modul bisnis (profit seperti invoicing, POS, CRM, inventori, maupun non-profit seperti donasi, kepengurusan yayasan, pendaftaran sekolah) membutuhkan layanan infrastruktur umum yang identik:
1. Penyimpanan file (bukti transfer, logo faktur, scan legalitas yayasan, lampiran proposal).
2. Konfigurasi dinamis per-tenant (mata uang, kop surat, timezone, format nota).
3. Penomoran otomatis berurutan anti-tabrakan (`INV/2026/0001`, `DON/2026/0001`, `RM-000123`).
4. Penjadwalan tugas rutin (iuran bulanan, tagihan berkala, sinkronisasi data).
5. Pengiriman email transaksional dan alur aktivasi akun/undangan.
6. Penyeragaman pagination tabel data.

Tanpa adanya layanan ini di Core, setiap modul bisnis akan membuat logika duplikat di controllernya sendiri, melanggar prinsip *Zero Infrastructure Leakage* (Rule 08).

## 2. Goals & Non-Goals
### Goals
- Mengimplementasikan `Core\Storage\StorageManager` terisolasi tenant (`storage/app/tenants/{tenant_id}/`) dengan validasi MIME, ukuran kuota, dan signed download URL.
- Mengimplementasikan `Core\Settings\TenantSettings` (key-value storage dengan namespace modul dan caching per request).
- Menyediakan profil organisasi per tenant (`org_type`: for_profit, non_profit, clinic, school, dsb.) dan helper format lokal (`money()`, `date_local()`).
- Mengimplementasikan generator penomoran berurutan aman konkurensi (`Sequence::next()`).
- Mengimplementasikan engine penjadwalan tugas internal (`php aio schedule:run`) yang dipicu dari satu cron sistem.
- Mengimplementasikan `Core\Mail\Mailer` (driver `log` dan `smtp` via `symfony/mailer`) yang terintegrasi dengan Queue asinkron, lengkap dengan alur undangan anggota dan reset password.
- Menyediakan helper pagination universal di backend dan komponen `<Pagination>` di frontend.

### Non-Goals
- Tidak mencakup payment gateway atau visual PDF generator di Core (dibuat sebagai modul bisnis mandiri).

---

## 3. Technical Architecture & Design

### Universal Services Ecosystem
```
[Business Modules: CRM / Invoicing / Donasi / Klinik]
                 │
  ┌──────────────┼──────────────┬──────────────┬──────────────┐
  ▼              ▼              ▼              ▼              ▼
Storage       Settings       Sequence       Schedule        Mail
(Tenant-iso)  (Key-Value)    (Auto-Number)  (Daemon/Cron)   (Queue Async)
```

---

## 4. Atomic Task Checklist

- [x] **Phase 1: Tenant-Isolated File Storage**
  - [x] 1.1 Buat interface `StorageDriverInterface` dan implementasi `LocalStorageDriver`.
  - [x] 1.2 Buat tabel database `files` untuk metadata, checksum, ownership, dan relasi polimorfik.
  - [x] 1.3 Implementasikan `Core\Storage\StorageManager` dengan isolasi folder otomatis per `tenant_id`.
  - [x] 1.4 Tambahkan aturan validasi file di `Validator` (`file`, `mimes:pdf,jpg,png`, `max_kb:2048`).
  - [x] 1.5 Implementasikan rute download privat bertanda tangan (signed URL dengan TTL).

- [x] **Phase 2: Tenant Settings & Multi-Domain Organization Profile**
  - [x] 2.1 Tambahkan kolom `org_type`, `timezone`, `currency`, dan `locale` pada tabel `tenants`.
  - [x] 2.2 Buat tabel `tenant_settings` (`tenant_id`, `module`, `key`, `value`, `type`).
  - [x] 2.3 Implementasikan service `TenantSettings` dan helper global `setting(key, default)`.
  - [x] 2.4 Implementasikan helper formatting seragam: `format_currency()` / `money()`, `format_date_local()`.
  - [x] 2.5 Buat halaman pengaturan profil organisasi di antarmuka web Settings.

- [x] **Phase 3: Atomic Document Sequence Generator**
  - [x] 3.1 Buat tabel database `sequences` (`tenant_id`, `name`, `prefix`, `current_value`, `padding`, `reset_period`).
  - [x] 3.2 Implementasikan `Core\Sequence\SequenceManager` dengan locking atomik SQLite untuk mencegah duplikasi nomor faktur/surat.
  - [x] 3.3 Tulis unit test konkurensi untuk verifikasi zero duplicate numbering.

- [x] **Phase 4: Scheduled Task Runner**
  - [x] 4.1 Buat interface penjadwalan `Schedule` yang dapat didaftarkan dari `ModuleProvider`.
  - [x] 4.2 Buat sub-perintah CLI `php aio schedule:run` dengan mekanisme lock anti-overlap.
  - [x] 4.3 Tambahkan pencatatan riwayat eksekusi task runner ke tabel/log.

- [x] **Phase 5: Mailer & Account Lifecycle Workflows**
  - [x] 5.1 Pasang paket `symfony/mailer` dan buat abstraction `Core\Mail\Mailer` (driver `log` dan `smtp`).
  - [x] 5.2 Integrasikan pengiriman email dengan Database Queue (`SendMailJob`).
  - [x] 5.3 Buat alur undangan anggota ke workspace (generate invitation token, link aktivasi via email).
  - [x] 5.4 Buat alur lupa password dan reset password berbasis token kedaluwarsa.

- [x] **Phase 6: Universal Pagination**
  - [x] 6.1 Implementasikan helper pagination `Paginator` di backend (`TenantScope::paginate()`).
  - [x] 6.2 Buat komponen reusable React `<Pagination>` yang patuh semantic design tokens.
