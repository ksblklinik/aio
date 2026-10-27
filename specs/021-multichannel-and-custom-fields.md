# SPEC-021: Multi-Channel Notification Broadcaster and Dynamic Custom Field Registry

- **Status**: `Completed`
- **Owner / Author**: Core Architecture Team
- **Tanggal Dibuat**: 2026-10-27
- **Target File / Modul**: `core/Notification/`, `core/CustomField/`, `database/migrations/`, `core/Foundation/Application.php`, `core/helpers.php`

---

## 1. Problem Statement & Context
Aplikasi modern (NGO kesekretariatan, canvassing lapangan, CRM, pengadaan) membutuhkan:
1. **Multi-Channel Notification Broadcaster**:
   - Di Indonesia, notifikasi penting (seperti disposisi surat darurat, peringatan kuorum rapat, pesanan pickup driver) sering tidak terbaca jika hanya mengandalkan in-app alert. Dibutuhkan saluran instan seperti WhatsApp, Telegram, dan Email.
   - Tanpa layer broadcaster terpadu di Core, modul bisnis akan melakukan HTTP call mandiri ke API WhatsApp/Telegram secara berulang (*Rule 08: Zero Infrastructure Leakage*).
2. **Dynamic Custom Field Registry**:
   - Setiap organisasi/tenant memiliki kebutuhan atribut unik (misal: yayasan yatim butuh kolom "Nama Wali", yayasan beasiswa butuh "IPK", CRM butuh "Budget Prospek").
   - Menjalankan `ALTER TABLE` setiap kali ada field baru sangat berbahaya dan memicu *schema drift*. Diperlukan Custom Field Registry berbasis EAV / JSON terisolasi per tenant.

## 2. Goals & Non-Goals
### Goals
- [x] Menyediakan `Core\Notification\ChannelManager` dengan dukungan multi-channel: `database` (in-app), `email`, `whatsapp`, dan `telegram`.
- [x] Menyediakan mockable channel drivers untuk unit test offline-first dan integrasi HTTP native untuk WhatsApp & Telegram tanpa vendor library berat.
- [x] Menyediakan skema database `custom_field_definitions` dan `custom_field_values` dengan isolasi ketat per tenant.
- [x] Menyediakan `Core\CustomField\FieldManager` untuk mendaftarkan definisi field dinamis, validasi input menggunakan `Validator`, penyimpanan, dan pengambilan data atribut.
- [x] Menyediakan helper global `notify()` dan `custom_fields()`.

### Non-Goals
- Tidak mencakup setup SMS gateway telco GSM fisik (menggunakan provider webhook HTTP umum).
- Tidak mencakup form visual drag-and-drop designer (merupakan domain frontend UI).

## 3. Technical Architecture & Design
### Multi-Channel Notification
```
[Business Event / Action]
           │
           ▼
 notify($user, $tenantId)->via(['database', 'whatsapp', 'telegram'])->send($title, $message)
           │
   ┌───────┼───────────────────┐
   ▼       ▼                   ▼
[Database] [WhatsApp Channel]  [Telegram Channel]
 (In-App)   (HTTP Webhook)      (Bot API)
```

### Dynamic Custom Fields
```
Tabel custom_field_definitions:
  id, tenant_id, entity_type (e.g. 'member'), field_name, label, type, options, is_required

Tabel custom_field_values:
  id, tenant_id, entity_type, entity_id, field_id, value, updated_at
```

### Function Signatures
- `ChannelManager::channel(string $name): NotificationChannelInterface`
- `ChannelManager::send(int|string $tenantId, mixed $recipient, string $title, string $message, array $channels = ['database'], array $options = []): array`
- `FieldManager::define(string $entityType, string $fieldName, string $label, string $type, array $options = [], bool $isRequired = false, int|string|null $tenantId = null): array`
- `FieldManager::getFields(string $entityType, int|string|null $tenantId = null): array`
- `FieldManager::saveValues(string $entityType, int|string $entityId, array $values, int|string|null $tenantId = null): bool`
- `FieldManager::getValues(string $entityType, int|string $entityId, int|string|null $tenantId = null): array`
- Global helper: `notify(mixed $recipient = null, int|string|null $tenantId = null)`
- Global helper: `custom_fields(?string $entityType = null)`

---

## 4. Edge Cases & Error Scenarios
- **Kasus 1: Saluran Eksternal Offline / Gagal**: Jika API WhatsApp/Telegram merespons error atau timeout, pengiriman ke saluran lain (misal in-app database) tidak boleh dibatalkan; catat log status pengiriman per channel.
- **Kasus 2: Isolasi Nilai Custom Field per Tenant**: Tenant 1 dan Tenant 2 memiliki field dengan nama sama `'nomor_induk'`. Nilai entitas antar-tenant tidak boleh bocor.
- **Kasus 3: Validasi Tipe Data Field Dinamis**: Input bertipe `number` yang dikirim berupa huruf harus gagal validasi.

---

## 5. Test Strategy
- **Unit Test**: `tests/Unit/CoreMultiChannelAndCustomFieldsTest.php`
  - Validasi multi-channel broadcast (database in-app, email, mock whatsapp, mock telegram).
  - Validasi penanganan kegagalan salah satu channel secara graceful.
  - Validasi registrasi custom field definitions dan isolasi tenant.
  - Validasi penyimpanan dan pembacaan custom field values (EAV).
  - Validasi form input custom field terhadap tipe data (`number`, `text`, `select`, `boolean`).

---

## 6. Atomic Task Checklist

- [x] **Phase 1: Multi-Channel Notification Engine**
  - [x] 1.1 Buat kontrak `Core\Notification\Channels\NotificationChannelInterface`.
  - [x] 1.2 Buat `DatabaseChannel`, `EmailChannel`, `WhatsAppChannel`, `TelegramChannel`.
  - [x] 1.3 Buat `Core\Notification\ChannelManager` dan helper `notification()`.
- [x] **Phase 2: Dynamic Custom Fields Registry**
  - [x] 2.1 Buat skema migrasi `database/migrations/2026_10_27_000018_create_custom_fields_tables.sql` dan eksekusi via CLI.
  - [x] 2.2 Buat `Core\CustomField\FieldManager` (`define`, `getFields`, `saveValues`, `getValues`, `validate`).
  - [x] 2.3 Daftarkan singletons di `core/Foundation/Application.php` dan helper `custom_fields()`.
- [x] **Phase 3: Unit Testing & TDD Verification**
  - [x] 3.1 Buat `tests/Unit/CoreMultiChannelAndCustomFieldsTest.php`.
  - [x] 3.2 Uji pengiriman multi-channel notification dan mock driver.
  - [x] 3.3 Uji registrasi custom field, validasi data, dan isolasi tenant.
- [x] **Phase 4: Verification & Git Integration**
  - [x] 4.1 Jalankan `pwsh -File ./scripts/verify.ps1`.
  - [x] 4.2 Lakukan atomic commit di branch `feat/021-multichannel-and-custom-fields`.
  - [x] 4.3 Merge ke `main` dengan `--no-ff`.
  - [x] 4.4 Perbarui `specs/README.md` dan `.agents/SESSION_HANDOFF.md`.
