# SPEC-031: Universal Facilities, Spaces & Locations Registry

- **Status**: `Draft`
- **Owner / Author**: Antigravity Platform Team
- **Tanggal Dibuat**: 2026-10-28
- **Target Modul**: `app/Modules/Facility/`
- **Tingkat Arsitektur**: `Level 2 (Shared Business Foundation)`

---

## 1. Problem Statement & Context
Setiap organisasi yang beroperasi di dunia nyata—baik klinik kesehatan, kantor pelayanan publik, event organizer, toko retail berjejaring, gudang logistik, hingga yayasan nirlaba—memiliki dan mengelola **ruang fisik** (*physical spaces*):
1. **Fragmentasi Manajemen Ruang & Lokasi**: Tanpa modul Level 2 yang universal, modul bisnis di masa depan (e.g., modul rawat jalan klinik, modul reservasi gedung EO, modul posko darurat NGO) akan tergoda membuat tabel "ruangan" atau "lokasi" lokal sendiri-sendiri, mengakibatkan duplikasi skema (*schema duplication*) dan kebocoran arsitektur (*infrastructure leakage*).
2. **Ketiadaan Hubungan Hierarki Ruang Terstandarisasi**: Sebuah organisasi biasanya memiliki hierarki 3 lapis:
   - **Gedung / Cabang / Posko** (*Location*),
   - **Ruangan / Unit Fungsional** (*Room / Department / Warehouse*),
   - **Aset / Slot Terkecil yang Dapat Ditempati** (*Space: Bed, Meja Pelayanan, Kursi Periksa, Booth, Rak Penyimpanan*).
3. **Kebutuhan Multi-Warehouse untuk Inventori**: Modul `Inventory` (SPEC-029) saat ini baru melacak saldo stok tunggal per tenant. Untuk mendukung klinik (Gudang Induk Farmasi vs Apotek Rawat Jalan) atau retail/NGO (Gudang Utama vs Posko Lapangan), inventori memerlukan master ruangan gudang yang valid dan terpusat dari modul Facility.
4. **Kebutuhan Penjadwalan & Antrean (SPEC-032)**: Mesin penjadwalan dan antrean membutuhkan identitas ruangan/loket yang jelas (e.g., Dokter A praktik di Ruang Poli Umum Lt. 1, Meja 2).

Solusinya adalah membangun **Modul Level 2: `Facility` (Universal Facilities, Spaces & Locations Registry)** yang mengelola master lokasi, ruangan fungsional, dan unit ruang/aset secara multi-tenant, hierarkis, dan siap dikonsumsi oleh seluruh modul lain.

---

## 2. Goals & Non-Goals

### Goals
- **Master Lokasi Fisik / Gedung (`facilities_locations`)**:
  - Mengelola entitas gedung, kantor cabang, posko wilayah, atau site operasional.
  - Penomoran kode otomatis tahunan via `sequence('facility_location', 'LOC', 5, 'yearly')` (e.g., `LOC/2026/00001`) atau input kustom (e.g., `HQ`, `CAB-BDG`).
  - Menyimpan data alamat, kota, nomor telepon, dan status aktif.
- **Master Ruangan Fungsional (`facilities_rooms`)**:
  - Mengelompokkan ruangan di bawah lokasi fisik dan lantai tertentu (*floor*).
  - Tipe fungsional universal:
    - `service` (Ruang Pelayanan / Poli / Konsultasi / Servis),
    - `storage` (Gudang Penyimpanan / Apotek / Depo Logistik),
    - `inpatient` (Kamar Perawatan / Rawat Inap / Hotel),
    - `office` (Ruang Administrasi / Kantor),
    - `meeting` (Ruang Rapat / Aula / Ballroom Event),
    - `procedure` (Ruang Tindakan / Operasi / UGD / Laboratorium),
    - `other` (Lain-lain).
  - Kapasitas ruangan (*capacity*), catatan fasilitas, dan status aktif.
- **Master Unit Ruang / Aset Mikro (`facilities_spaces`)**:
  - Mengelola slot mikro di dalam ruangan yang dapat ditempati atau dipesan:
    - `bed` (Tempat Tidur Pasien / Kasur Perawatan),
    - `desk` (Meja Loket Pendaftaran / Meja Kasir),
    - `chair` (Kursi Periksa Gigi / Kursi Tindakan),
    - `dock` (Dermaga / Loading Dock / Rak Gudang),
    - `other` (Slot / Booth / Meja).
  - Status ketersediaan mikro: `available`, `occupied`, `maintenance`, `reserved`.
- **Exported Shared Contract Interface (`FacilityRegistryInterface`)**:
  - Global helper: `facilities(): FacilityRegistryInterface`.
  - Operasi CRUD terisolasi multi-tenant untuk lokasi, ruangan, dan unit ruang.
  - Fungsi instan ketersediaan: `listStorageRooms()` (khusus konsumsi modul Inventory), `getRoomOccupancy(roomId)`, `updateSpaceStatus(spaceId, status)`.
- **Web UI & REST API**:
  - Web UI untuk visualisasi hierarki gedung $\rightarrow$ ruangan $\rightarrow$ bed/meja dengan status badge visual.
  - REST API endpoint untuk integrasi mobile, kiosk pendaftaran, atau modul lain.

### Non-Goals
- Tidak mencakup sistem denah 3D/BIM interaktif dengan rendering CAD arsitektur real-time.
- Tidak mencakup sistem kontrol otomatisasi gedung (Smart IoT HVAC/Listrik) pada rilis Level 2 ini.

---

## 3. Technical Architecture & Design

### Hierarki Relasi Komponen Fisik
```
┌────────────────────────────────────────────────────────────────────────┐
│                        facilities_locations                            │
│           (Gedung Utama, Kampus B, Posko Lapangan Bencana)             │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │ 1:N
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│                          facilities_rooms                              │
│   (Poli Umum, Gudang Farmasi, Kamar Rawat 101, Ballroom Mahameru)     │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │ 1:N
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│                         facilities_spaces                              │
│    (Bed 01, Meja Loket Pendaftaran 2, Kursi Dental, Rak Farmasi A)     │
└────────────────────────────────────────────────────────────────────────┘
```

### Data Model & Database Schema

```
┌────────────────────────────────────────────────────────────────────────┐
│                         facilities_locations                           │
├────────────────────────────────────────────────────────────────────────┤
│ id (PK, int)                                                           │
│ tenant_id (FK, int)                                                    │
│ code (varchar, unique per tenant) e.g. LOC/2026/00001, HQ               │
│ name (varchar) e.g. Gedung Pusat, Klinik Cabang Melati, Posko Aceh     │
│ type ('headquarters'|'branch'|'site'|'warehouse'|'outpost'|'other')    │
│ address (text, nullable)                                               │
│ city (varchar, nullable)                                               │
│ phone (varchar, nullable)                                              │
│ is_active (bool, default true)                                         │
│ created_at, updated_at                                                 │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │ 1:N
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│                           facilities_rooms                             │
├────────────────────────────────────────────────────────────────────────┤
│ id (PK, int)                                                           │
│ tenant_id (FK, int)                                                    │
│ location_id (FK facilities_locations)                                  │
│ code (varchar, unique per tenant) e.g. POLI-01, GDG-FARMASI, RM-101    │
│ name (varchar) e.g. Poli Umum, Gudang Obat Induk, Kamar Melati 1       │
│ floor (varchar, default '1') e.g. '1', '2', 'Lt. Dasar'                │
│ type ('service'|'storage'|'inpatient'|'office'|'meeting'|'procedure')  │
│ capacity (int, default 1)                                              │
│ notes (text, nullable)                                                 │
│ is_active (bool, default true)                                         │
│ created_at, updated_at                                                 │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │ 1:N
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│                          facilities_spaces                             │
├────────────────────────────────────────────────────────────────────────┤
│ id (PK, int)                                                           │
│ tenant_id (FK, int)                                                    │
│ room_id (FK facilities_rooms)                                          │
│ code (varchar, unique per tenant) e.g. BED-01, MEJA-02, CHAIR-DENTAL   │
│ name (varchar) e.g. Tempat Tidur 1, Meja Pendaftaran 2                 │
│ type ('bed'|'desk'|'chair'|'dock'|'booth'|'other')                     │
│ status ('available'|'occupied'|'maintenance'|'reserved')               │
│ is_active (bool, default true)                                         │
│ created_at, updated_at                                                 │
└────────────────────────────────────────────────────────────────────────┘
```

### Shared Contract Interface (`FacilityRegistryInterface`)
```php
namespace App\Modules\Facility\Contracts;

interface FacilityRegistryInterface
{
    // Location Management
    public function createLocation(array $data): array;
    public function updateLocation(int $id, array $data): array;
    public function getLocation(int $id): ?array;
    public function listLocations(array $filters = []): array;
    public function deleteLocation(int $id): bool;

    // Room Management
    public function createRoom(array $data): array;
    public function updateRoom(int $id, array $data): array;
    public function getRoom(int $id): ?array;
    public function listRooms(array $filters = []): array;
    public function listStorageRooms(?int $locationId = null): array;
    public function deleteRoom(int $id): bool;

    // Space / Slot Management
    public function createSpace(array $data): array;
    public function updateSpace(int $id, array $data): array;
    public function getSpace(int $id): ?array;
    public function listSpaces(array $filters = []): array;
    public function updateSpaceStatus(int $id, string $status): array;
    public function deleteSpace(int $id): bool;

    // Availability & Occupancy
    public function getRoomOccupancy(int $roomId): array;
    public function getFacilitySummary(): array;
}
```

---

## 4. Edge Cases & Error Scenarios
- **Kasus 1: Penghapusan Lokasi yang Masih Memiliki Ruangan**: Mencegah penghapusan lokasi jika masih memiliki ruangan aktif terikat (`RuntimeException`).
- **Kasus 2: Penghapusan Ruangan yang Memiliki Unit Ruang / Stok Aktif**: Jika ruangan memiliki space aktif atau terdaftar sebagai gudang stok aktif, sistem menolak penghapusan permanen (harus dinonaktifkan via `is_active = false`).
- **Kasus 3: Perubahan Status Space Menjadi Occupied / Maintenance**: Status transisi divalidasi ketat terhadap enum: `available`, `occupied`, `maintenance`, `reserved`.
- **Kasus 4: Isolasi Multi-Tenant Mutlak**: Seluruh query lokasi, ruangan, dan unit ruang terlindungi oleh `tenant_id` aktif untuk mencegah kebocoran data antar-tenant.

---

## 5. Test Strategy
- **Unit & Integration Test Suite** (`tests/Unit/FacilityModuleTest.php`):
  1. `test_can_create_location_and_rooms_with_hierarchical_relations`
  2. `test_can_create_spaces_with_initial_available_status`
  3. `test_can_update_space_status_to_occupied_and_available`
  4. `test_cannot_delete_location_with_existing_active_rooms`
  5. `test_list_storage_rooms_only_returns_rooms_with_storage_type`
  6. `test_room_occupancy_calculates_total_available_and_occupied_spaces`
  7. `test_multi_tenant_isolation_in_facility_registry`
  8. `test_facility_summary_aggregates_total_locations_rooms_and_spaces`
- **Verification Gate**:
  - PHPUnit test suite 100% pass.
  - TypeScript typecheck (`tsc --noEmit`) 100% clean.
  - Pre-commit verification (`scripts/verify.ps1`).

---

## 6. Atomic Task Checklist

- [ ] **Phase 1: Setup Modul, Arsitektur, & Database Migrations**
  - [x] 1.1 Buat manifest modul `app/Modules/Facility/module.json`.
  - [x] 1.2 Buat migration `database/migrations/2026_10_28_000025_create_facility_tables.sql` untuk tabel `facilities_locations`, `facilities_rooms`, dan `facilities_spaces`.
  - [x] 1.3 Jalankan migrasi via console runner dan verifikasi skema database.
- [x] **Phase 2: Service Contracts & Core Engine Implementation (TDD)**
  - [x] 2.1 Buat interface `App\Modules\Facility\Contracts\FacilityRegistryInterface`.
  - [x] 2.2 Buat Service Provider `App\Modules\Facility\FacilityServiceProvider` dan daftarkan ke modul.
  - [x] 2.3 Daftarkan helper global `facilities()` di `core/helpers.php`.
  - [x] 2.4 Tulis test suite `tests/Unit/FacilityModuleTest.php` mencakup pembuatan gedung, ruangan, slot/bed, occupancy, dan isolasi tenant.
  - [x] 2.5 Implementasikan `App\Modules\Facility\Services\FacilityService` hingga seluruh skenario unit test lolos 100%.
- [x] **Phase 3: Web UI, Routes, & Programmatic REST API**
  - [x] 3.1 Buat routes modul di `app/Modules/Facility/routes.php`.
  - [x] 3.2 Implementasikan Web Controller `FacilityController` untuk manajemen lokasi, ruangan, dan unit bed/meja.
  - [x] 3.3 Implementasikan REST API Controller `ApiFacilityController` untuk lookup lokasi/ruangan/space programatik.
  - [x] 3.4 Buat view template atau halaman UI responsif dark/light mode untuk fasilitas & ruangan.
- [ ] **Phase 4: Final Verification, Review & Handoff**
  - [ ] 4.1 Jalankan seluruh test suite global via `vendor/bin/phpunit.bat` (memastikan tidak ada regresi).
  - [ ] 4.2 Jalankan `npm run typecheck` dan script `scripts/verify.ps1`.
  - [ ] 4.3 Perbarui `specs/README.md` dan `.agents/SESSION_HANDOFF.md`.
  - [ ] 4.4 Merge atomic commit ke branch `main`.
