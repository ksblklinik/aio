# Session Handoff State

Dokumen ini adalah *Single Source of Truth* untuk transfer konteks antar-sesi AI Coding di repositori **aio**. Dokumen ini dibaca secara otomatis setiap mengawali percakapan baru dan diperbarui setiap mengakhiri sesi.

---

## 📌 Status Terakhir Sesi (Last Updated: 2026-10-28 08:45 WIB)

* **Conversation ID Terakhir**: `4d7528e5-6993-4060-b918-14aabb4463fe`
* **Active Git Branch**: `main`
* **Status Dokumen / Roadmap**:
  - SPEC-013 ([`specs/013-core-hardening-and-unified-kernel.md`](file:///c:/aplikasi/aio/specs/013-core-hardening-and-unified-kernel.md)): `Completed` (merged to `main`).
  - SPEC-014 ([`specs/014-module-extension-contract.md`](file:///c:/aplikasi/aio/specs/014-module-extension-contract.md)): `Completed` (merged to `main`).
  - SPEC-015 ([`specs/015-universal-platform-services.md`](file:///c:/aplikasi/aio/specs/015-universal-platform-services.md)): `Completed` (merged to `main`).
  - SPEC-015B ([`specs/015b-platform-hardening-and-auth-ui.md`](file:///c:/aplikasi/aio/specs/015b-platform-hardening-and-auth-ui.md)): `Completed` (merged to `main`).
  - SPEC-017 ([`specs/017-core-enterprise-ai-and-interop.md`](file:///c:/aplikasi/aio/specs/017-core-enterprise-ai-and-interop.md)): `Completed` (merged to `main`).
  - SPEC-018 ([`specs/018-geo-ledger-and-idempotency.md`](file:///c:/aplikasi/aio/specs/018-geo-ledger-and-idempotency.md)): `Completed` (merged to `main`).
  - SPEC-019 ([`specs/019-lightweight-workflow-engine.md`](file:///c:/aplikasi/aio/specs/019-lightweight-workflow-engine.md)): `Completed` (merged to `main`).
  - SPEC-020 ([`specs/020-cache-and-media-qr-barcode.md`](file:///c:/aplikasi/aio/specs/020-cache-and-media-qr-barcode.md)): `Completed` (merged to `main`).
  - SPEC-021 ([`specs/021-multichannel-and-custom-fields.md`](file:///c:/aplikasi/aio/specs/021-multichannel-and-custom-fields.md)): `Completed` (merged to `main`).
  - SPEC-022 ([`specs/022-payment-and-quota-entitlements.md`](file:///c:/aplikasi/aio/specs/022-payment-and-quota-entitlements.md)): `Completed` (merged to `main`).
  - SPEC-023 ([`specs/023-import-export-and-document-engine.md`](file:///c:/aplikasi/aio/specs/023-import-export-and-document-engine.md)): `Completed` (merged to `main`).
  - SPEC-024 ([`specs/024-device-integration-toolkit.md`](file:///c:/aplikasi/aio/specs/024-device-integration-toolkit.md)): `Completed` (merged to `main`).
  - SPEC-025 ([`specs/025-advanced-security-hardening.md`](file:///c:/aplikasi/aio/specs/025-advanced-security-hardening.md)): `Completed` (merged to `main`).
  - SPEC-026 ([`specs/026-device-integrity-and-anti-fraud.md`](file:///c:/aplikasi/aio/specs/026-device-integrity-and-anti-fraud.md)): `Completed` (merged to `main`).
  - **SPEC-027 ([`specs/027-universal-contact-registry.md`](file:///c:/aplikasi/aio/specs/027-universal-contact-registry.md))**: `Completed` (merged to `main`).
  - **SPEC-028 ([`specs/028-universal-finance-engine.md`](file:///c:/aplikasi/aio/specs/028-universal-finance-engine.md))**: `Completed` (merged to `main`).
  - **SPEC-029 ([`specs/029-universal-inventory-and-items.md`](file:///c:/aplikasi/aio/specs/029-universal-inventory-and-items.md))**: `Completed` (merged to `main`).
  - **SPEC-029B ([`specs/029b-inventory-multi-warehouse-and-batch.md`](file:///c:/aplikasi/aio/specs/029b-inventory-multi-warehouse-and-batch.md))**: `Completed` (merged to `main`).
  - **SPEC-030 ([`specs/030-universal-billing-and-invoicing.md`](file:///c:/aplikasi/aio/specs/030-universal-billing-and-invoicing.md))**: `Completed` (merged to `main`).
  - **SPEC-031 ([`specs/031-universal-facilities-and-locations.md`](file:///c:/aplikasi/aio/specs/031-universal-facilities-and-locations.md))**: `Completed` (merged to `main`).
  - **SPEC-032 ([`specs/032-universal-scheduling-and-queue.md`](file:///c:/aplikasi/aio/specs/032-universal-scheduling-and-queue.md))**: `Completed` (merged to `main`).
  - **SPEC-033 ([`specs/033-universal-tariffs-and-split-fee.md`](file:///c:/aplikasi/aio/specs/033-universal-tariffs-and-split-fee.md))**: `Completed` (Universal Tariffs, Multi-Payer Price Books & Split Fee Engine).
  - **SPEC-016 ([`specs/016-template-packaging-and-release.md`](file:///c:/aplikasi/aio/specs/016-template-packaging-and-release.md))**: `Deferred` (Template Packaging, Sanitasi & Release Checklist).
* **Test Suite Status**: **406 tests (2358 assertions) PASS 100%**, `tsc --noEmit` clean, Vite build clean.
* **Database File**: `database.sqlite` di root repositori (28 migrasi applied).

---

## 📦 Capaian & Hasil Eksekusi SPEC-031 (Universal Facilities, Spaces & Locations Registry - Level 2 Shared Foundation):
1. **Modul Arsitektur Level 2 (`app/Modules/Facility/`)**:
   - Manifest terdaftar di kategori `resources` dengan rute `/facility` dan service provider `FacilityServiceProvider`.
   - Migrasi basis data terisolasi multi-tenant: `facilities_locations`, `facilities_rooms`, `facilities_spaces`.
2. **Model Relasi Fisik 3 Lapis (Hierarchical Physical Spaces)**:
   - **Gedung / Cabang / Posko (`facilities_locations`)**: Headquarters, Branch, Site, Warehouse, Outpost. Penomoran otomatis via `sequence('facility_location', 'LOC', 5, 'yearly')`.
   - **Ruangan Fungsional (`facilities_rooms`)**: Service, Storage, Inpatient, Office, Meeting, Procedure, Other. Lantai (*floor*), kapasitas, dan catatan fasilitas.
   - **Unit Mikro / Slot (`facilities_spaces`)**: Bed, Desk, Chair, Dock, Booth, Other. Status siklus ketersediaan mikro: `available`, `occupied`, `maintenance`, `reserved`.
3. **Penyediaan Fondasi untuk Multi-Warehouse & Antrean**:
   - `listStorageRooms(?int $locationId = null)`: Khusus menyediakan daftar ruangan bertipe gudang (`storage`) untuk konsumsi modul Inventory multi-warehouse (SPEC-029B).
   - `getRoomOccupancy(int $roomId)`: Menghitung jumlah slot, ketersediaan, terisi, dalam perbaikan, serta persentase *occupancy rate* secara instan.
   - `getFacilitySummary()`: Ringkasan agregat seluruh gedung, ruangan, depo penyimpanan, dan unit bed/meja.
4. **Exported Shared Contract Interface (`FacilityRegistryInterface`)**:
   - Global helper: `facilities(): FacilityRegistryInterface`.
5. **Web Management & Programmatic REST API**:
   - Web Controller (`FacilityController`) untuk hierarki denah gedung $\rightarrow$ ruangan $\rightarrow$ bed/slot.
   - REST API Controller (`ApiFacilityController`) untuk integrasi sistem kiosk, IoT, atau modul bisnis eksternal.
   - Halaman antarmuka Inertia React (`resources/js/Pages/Facility/Index.tsx`) responsif dark/light mode menggunakan semantic tokens baku.
6. **Verifikasi & Test**:
   - 16 unit test komprehensif di [`tests/Unit/FacilityModuleTest.php`](file:///c:/aplikasi/aio/tests/Unit/FacilityModuleTest.php) lolos 100%.

---

## 📦 Capaian & Hasil Eksekusi SPEC-029B (Inventory Multi-Warehouse, Stock Transfer, Batch & Expiry FEFO):
1. **Database Schema & Migrations**:
   - Migrasi `2026_10_28_000026_add_multi_warehouse_and_batches.sql` membuat tabel `item_batches`, `inventory_stock_locations`, dan `inventory_transfers`.
   - Modifikasi `inventory_movements` dengan kolom `room_id` dan `batch_id`.
2. **Core FEFO Engine & Multi-Warehouse Contracts (`InventoryRegistryInterface`)**:
   - `getStockByLocation(itemId, ?roomId)` & `getLocationStock(itemId, roomId)`: Stok item per lokasi gudang/ruangan penyimpanan.
   - `createBatch()`, `getBatch()`, `listBatches()`, `updateBatchStatus()`: Manajemen batch & penelusuran identitas lot.
   - `listNearExpiryItems(daysThreshold)`: Query otomatis batch obat/barang mendekati kedaluwarsa dengan kalkulasi hari `julianday`.
   - `deductStockFefo()`: Algoritma alokasi First-Expired First-Out memotong batch kadaluarsa terdekat terlebih dahulu secara otomatis.
   - `transferStock()`: Mutasi atomik pemindahan stok antar-gudang berurutan dengan sequence `TRF/YYYY/XXXXX`.
3. **Integrasi Lintas Modul (`Billing` + `Inventory`)**:
   - `BillingService::deductItemsStock()` dan `revertItemsStock()` mendukung `room_id` dan memotong stok via algoritma FEFO jika item memiliki batch aktif.
   - Penambahan unit test integrasi di `BillingModuleTest` memverifikasi alokasi FEFO dan retur/pembatalan faktur mengembalikan stok ke batch yang tepat.
4. **Web UI & REST API**:
   - REST API endpoints di `ApiInventoryController`.
   - Web routes di `routes.php` dan `InventoryController`.
   - Antarmuka pengguna responsif Inertia React di `resources/js/Pages/Inventory/Index.tsx` dengan Semantic UI Tokens, tab Multi-Gudang, riwayat transfer, dan monitoring peringatan FEFO kedaluwarsa.
5. **Verifikasi & Test**:
   - Test suite baru [`tests/Unit/InventoryMultiWarehouseTest.php`](file:///c:/aplikasi/aio/tests/Unit/InventoryMultiWarehouseTest.php) (8 tests, 48 assertions) lolos 100%.
   - Global test suite meningkat menjadi **386 tests (2194 assertions)** PASS 100%.

---

## 📦 Capaian & Hasil Eksekusi SPEC-032 (Universal Scheduling, Appointments & Human Queue Engine):
1. **Database Schema & Migrations**:
   - Migrasi `2026_10_28_000027_create_schedule_and_queue_tables.sql` membuat tabel `resource_schedules`, `appointments`, dan `queue_tickets` dengan foreign key ke `facilities_rooms` dan `facilities_spaces`.
2. **Core Scheduling & Human Queue Engine (`ScheduleRegistryInterface`)**:
   - `createSchedule()`, `listSchedules()`, `isSlotAvailable()`: Pengaturan jadwal alokasi shift dan slot kapasitas sumber daya (personil, dokter, staf, ruangan, peralatan).
   - `bookAppointment()`, `getAppointment()`, `listAppointments()`, `updateAppointmentStatus()`: Booking janji temu multi-saluran terisolasi per tenant dengan kode booking otomatis `APT/YYYYMMDD/XXXXX`.
   - `issueTicket()`: Pengambilan tiket antrean pendaftaran/layanan dengan nomor tiket harian terformat per prefix (`A-001`, `B-001`, dst.) yang reset harian.
   - `callNextTicket()`, `recallTicket()`, `serveTicket()`, `completeTicket()`, `cancelTicket()`, `skipTicket()`: State machine siklus hidup tiket antrean (`waiting` $\rightarrow$ `calling` $\rightarrow$ `serving` $\rightarrow$ `completed`/`skipped`/`cancelled`).
   - `getDisplayState()`: Data waktu nyata untuk konsumsi layar TV display ruang tunggu (tiket sedang dipanggil di setiap loket/ruangan, antrean berikutnya, dan statistik harian).
3. **Web UI & Waiting Room TV Display**:
   - Rute Web di `routes.php` dan `ScheduleController`.
   - REST API di `ApiScheduleController` untuk mesin kiosk pendaftaran mandiri (APM) dan integrasi eksternal.
   - Halaman Pemanggil Antrean & Janji Temu di `resources/js/Pages/Schedule/Index.tsx` dengan Semantic UI Tokens.
   - Layar TV Display Ruang Tunggu di `resources/js/Pages/Schedule/Display.tsx` dilengkapi Web Speech API Text-to-Speech (TTS) otomatis memanggil "Nomor Antrean ... Silakan menuju ke ...", toggle layar penuh (*full screen*), dan real-time polling interval.
4. **Verifikasi & Test**:
   - Test suite baru [`tests/Unit/ScheduleModuleTest.php`](file:///c:/aplikasi/aio/tests/Unit/ScheduleModuleTest.php) (10 tests, 62 assertions) lolos 100%.
   - Global test suite meningkat menjadi **396 tests (2275 assertions)** PASS 100%.

---

## 📦 Capaian & Hasil Eksekusi SPEC-033 (Universal Tariffs, Multi-Payer Price Books & Split Fee Engine):
1. **Database Schema & Migrations**:
   - Migrasi `2026_10_28_000028_create_tariff_and_split_fee_tables.sql` membuat tabel `tariff_price_books`, `tariff_rates`, `tariff_split_rules`, dan `tariff_fee_allocations`.
2. **Core Tariffs & Revenue Split Engine (`TariffRegistryInterface`)**:
   - `createPriceBook()`, `listPriceBooks()`, `getDefaultPriceBook()`: Buku tarif multi-penjamin (Umum, BPJS, Asuransi Mandiri/Prudential, Grosir) dengan mekanisme toggle default terisolasi multi-tenant.
   - `setRate()`, `getRate()`, `listRates()`, `resolvePrice()`: Resolusi tarif berjenjang per item layanan/obat, kelas ruangan (VIP vs standard), skema penjaminan (fixed, percentage, cap limit), dan kalkulasi co-payment pasien mandiri dengan graceful fallback ke harga katalog.
   - `createSplitRule()`, `listSplitRules()`, `calculateSplits()`: Pemecahan komponen biaya (Jasa Medis Dokter, Jasa Sarana Klinik, Komisi Sales/Mekanik, Biaya Administrasi) dengan validasi batas akumulasi persentase maksimal 100%.
   - `recordInvoiceSplits()`: Perekaman otomatis alokasi fee saat transaksi invoice diterbitkan.
   - `getBeneficiarySummary()`, `settleBeneficiaryFees()`: Pelacakan akumulasi fee dokter/mitra (akrual vs terbayar) dan eksekusi pencairan/disbursement honorarium.
3. **Web UI & REST API**:
   - Rute Web di `routes.php` dan `TariffController`.
   - REST API di `ApiTariffController` untuk integrasi kasir POS, anjungan kiosk, dan bridging eksternal.
   - Antarmuka pengguna responsif Inertia React di `resources/js/Pages/Tariff/Index.tsx` dengan Semantic UI Tokens, 3 Tab navigasi (Matriks Buku Tarif, Aturan Split Fee, dan Pencairan/Settlement).
4. **Verifikasi & Test**:
   - Test suite baru [`tests/Unit/TariffModuleTest.php`](file:///c:/aplikasi/aio/tests/Unit/TariffModuleTest.php) (10 tests, 64 assertions) lolos 100%.
   - Global test suite meningkat menjadi **406 tests (2358 assertions)** PASS 100%.

---

## 🚀 Rencana Langkah Berikutnya (Penguatan Core & Level 2):
1. **SPEC-034: Universal Semantic Coding & Terminology Registry**:
   - Standard dictionary engine untuk lookup ribuan kode standar internasional (ICD-10, ICD-9-CM, LOINC, KFA / BPJS DPHO, HS Code, KBLI) dengan fast search, sinonim, dan mapping lintas standar.
2. **SPEC-035: Universal Document Cryptographic Seal & Verification Portal**:
   - Digital signature / TTE, segel kriptografis SHA-256, QR code validasi publik (`/verify/doc/{hash}`) untuk kwitansi, invoice, surat sakit, resume medis, atau e-certificate.
3. **Core Hardening: Universal Server-Sent Events (SSE) Push Bus**:
   - Menggantikan polling interval pada TV Display antrean (SPEC-032) dengan real-time push sub-detik native browser.


