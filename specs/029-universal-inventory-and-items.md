# SPEC-029: Universal Items, Catalog & Inventory Engine

- **Status**: `Completed`
- **Owner / Author**: Antigravity Platform Team
- **Tanggal Dibuat**: 2026-10-27
- **Target Modul**: `app/Modules/Inventory/`
- **Tingkat Arsitektur**: `Level 2 (Shared Business Foundation)`

---

## 1. Problem Statement & Context
100% entitas organisasi di dunia—baik perusahaan rintisan, toko retail, klinik kesehatan, kantor konsultan jasa, sekolah, bengkel, salon, hingga yayasan nirlaba—memerlukan manajemen master katalog item dan pencatatan sumber daya:
1. **Fragmentasi Katalog Barang & Jasa**: Bisnis jasa murni (konsultan, software house) sering kesulitan menggunakan modul ERP tradisional karena dipaksa mengisi gudang fisik dan serial number. Sebaliknya, klinik dan retail dipusingkan bila modul POS/Kasir mereka tidak memiliki pelacakan stok obat/barang dagangan.
2. **Ketiadaan Satuan Ukuran Terstandarisasi (UOM)**: Perbedaan satuan antara pembelian (e.g., Dus/Box) dan penjualan (e.g., Strip/Pcs) sering menyebabkan kekacauan penghitungan stok dan selisih nilai fisik.
3. **Ketiadaan Riwayat Audit Mutasi Stok (Stock Ledger)**: Stok tiba-tiba berkurang tanpa jejak siapa yang mengeluarkan, untuk keperluan apa (penjualan, sampel gratis, rusak/expired, atau pemakaian internal).
4. **Stok Menipis Tanpa Peringatan Dini (Stockout Risk)**: Pengelola usaha baru menyadari obat atau produk habis saat pelanggan/pasien sedang memesan di depan kasir.

Solusinya adalah membangun **Modul Level 2: `Inventory` (Universal Items, Catalog & Inventory Engine)** yang agnostik untuk barang fisik maupun jasa, mengelola satuan ukuran terstandarisasi, melacak mutasi stok atomik dengan audit trail, serta menyediakan kontrak shared service (`items(): InventoryRegistryInterface`) yang siap dikonsumsi modul operasional lain di masa depan.

---

## 2. Goals & Non-Goals

### Goals
- **Master Katalog Agnostik (`items`)**:
  - Mendukung tipe: `goods` (Barang Fisik bersaldo stok), `service` (Jasa/Layanan non-stok), `raw_material` (Bahan Baku), dan `bundle` (Paket gabungan).
  - Penomoran kode item otomatis via `sequence('inventory', 'ITM', 5, 'yearly')` (e.g., `ITM/2026/00001`) atau input custom SKU/Barcode (`barcode`, `sku`).
  - Penetapan harga dasar: Harga Beli (`cost_price`) dan Harga Jual (`selling_price`).
  - Ambang batas peringatan stok menipis (`min_stock_alert`).
- **Satuan Ukuran Universal / Unit of Measure (`inventory_units`)**:
  - Pengelolaan satuan standar: `PCS`, `BOX`, `STRIP`, `BTL`, `KG`, `LTR`, `JAM`, `SESI` dengan seeder bawaan otomatis.
- **Kategori Item Hirarkis (`item_categories`)**:
  - Pengelompokan berjenjang untuk mempermudah pencarian dan pengelompokan laporan.
- **Pelacakan Saldo & Mutasi Stok Fisik (`inventory_movements`)**:
  - Jenis mutasi: `in` (Barang Masuk/Pembelian), `out` (Barang Keluar/Penjualan/Pemakaian), `adjustment` (Penyesuaian), dan `opname` (Audit Fisik).
  - Setiap mutasi mencatat kuantitas, saldo sebelum & sesudah, alasan, nomor referensi dokumen, dan pelaksana.
  - Dilengkapi perlindungan terhadap stok minus (*anti-negative stock guard*).
- **Exported Shared Contract Interface (`InventoryRegistryInterface`)**:
  - Global helper: `items(): InventoryRegistryInterface` dan `inventory(): InventoryRegistryInterface`.
  - Fungsi instan: `createItem()`, `getItem()`, `updateItem()`, `adjustStock()`, `getStock()`, `checkAvailability()`, `listLowStockItems()`.
- **Web UI & REST API**:
  - Web UI untuk manajemen master item, katalog, dan penyesuaian stok.
  - REST API endpoint untuk pencarian autocomplete katalog dan validasi ketersediaan stok secara terprogram.

### Non-Goals
- Tidak mencakup multi-gudang lintas benua dengan kalkulasi landed-cost pajak bea cukai impor kontainer rumit.

---

## 3. Database Schema Design

```
┌─────────────────────────────────────────────────────────────────┐
│                       inventory_units                           │
├─────────────────────────────────────────────────────────────────┤
│ id (PK, int)                                                    │
│ tenant_id (FK, int)                                             │
│ code (varchar) e.g. PCS, BOX, STRIP, JAM                        │
│ name (varchar) e.g. Pieces, Kotak, Strip, Jam                   │
│ is_active (bool, default true)                                  │
│ created_at, updated_at                                          │
└────────────────┬────────────────────────────────────────────────┘
                 │ 1:N
                 ▼
┌─────────────────────────────────────────────────────────────────┐
│                            items                                │
├─────────────────────────────────────────────────────────────────┤
│ id (PK, int)                                                    │
│ tenant_id (FK, int)                                             │
│ code (varchar, unique per tenant) e.g. ITM/2026/00001           │
│ sku (varchar, nullable) e.g. MED-PAR-500                        │
│ barcode (varchar, nullable) e.g. 8991234567890                  │
│ name (varchar) e.g. Paracetamol 500mg, Jasa Konsultasi          │
│ type ('goods' | 'service' | 'raw_material' | 'bundle')          │
│ category_id (FK item_categories, nullable)                      │
│ unit_id (FK inventory_units, nullable)                          │
│ cost_price (decimal/float, default 0.0)                         │
│ selling_price (decimal/float, default 0.0)                      │
│ current_stock (decimal/float, default 0.0)                      │
│ min_stock_alert (decimal/float, default 0.0)                    │
│ track_inventory (bool, default true)                            │
│ is_active (bool, default true)                                  │
│ description (text, nullable)                                    │
│ created_at, updated_at                                          │
└────────────────┬────────────────────────────────────────────────┘
                 │ 1:N
                 ▼
┌─────────────────────────────────────────────────────────────────┐
│                     inventory_movements                         │
├─────────────────────────────────────────────────────────────────┤
│ id (PK, int)                                                    │
│ tenant_id (FK, int)                                             │
│ movement_number (varchar, unique per tenant) e.g. MOV/...       │
│ item_id (FK items)                                              │
│ type ('in' | 'out' | 'adjustment' | 'opname')                   │
│ quantity (decimal/float)                                        │
│ stock_before (decimal/float)                                    │
│ stock_after (decimal/float)                                     │
│ unit_cost (decimal/float, nullable)                             │
│ reference_type (varchar, nullable) e.g. 'sale', 'purchase'     │
│ reference_id (varchar, nullable)                                │
│ notes (text, nullable)                                          │
│ created_by (FK users, nullable)                                 │
│ created_at (timestamp)                                          │
└─────────────────────────────────────────────────────────────────┘
```

---

## 4. Technical Architecture & Shared Contract

### Service Interface: `InventoryRegistryInterface`
```php
namespace App\Modules\Inventory\Contracts;

interface InventoryRegistryInterface
{
    // Master Item & Katalog
    public function createItem(array $data): int;
    public function updateItem(int $itemId, array $data): bool;
    public function getItem(int $itemId): ?array;
    public function getItemByCode(string $code): ?array;
    public function getItemByBarcode(string $barcode): ?array;
    public function searchItems(string $query, ?string $type = null, int $limit = 50): array;

    // Manajemen Stok & Mutasi
    public function getStock(int $itemId): float;
    public function checkAvailability(int $itemId, float $requiredQuantity): bool;
    public function recordStockMovement(
        int $itemId,
        float $quantity,
        string $type, // 'in', 'out', 'adjustment', 'opname'
        string $notes = '',
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?float $unitCost = null,
        ?int $userId = null
    ): int;

    // Satuan & Kategori
    public function listUnits(): array;
    public function createUnit(string $code, string $name): int;
    public function listCategories(): array;
    public function createCategory(string $code, string $name, ?int $parentId = null): int;

    // Ringkasan & Peringatan Stok
    public function listLowStockItems(): array;
    public function getStockMovementHistory(int $itemId, int $limit = 50): array;
}
```

---

## 5. Test Strategy
- **Unit Testing**:
  - `tests/Unit/InventoryModuleTest.php`:
    - Pengujian pembuatan item bertipe `goods` dan `service`.
    - Pengujian auto-seeding satuan ukuran baku (`PCS`, `BOX`, dll.).
    - Pengujian penomoran otomatis kode item via `sequence('inventory')`.
    - Pengujian mutasi stok masuk (`in`) dan bertambahnya saldo fisik secara atomik.
    - Pengujian mutasi stok keluar (`out`) dan perlindungan anti-stok minus (*insufficient stock guard*).
    - Pengujian penyesuaian stok (*adjustment*) dan opname (*opname*).
    - Pengujian peringatan stok menipis (`listLowStockItems()`).
    - Pengujian item bertipe `service` (bebas pemotongan saldo stok fisik).
    - Pengujian isolasi data antar-tenant.
    - Pengujian helper global `items()` dan `inventory()`.
- **Integration Verification**:
  - Eksekusi `./scripts/verify.ps1` (seluruh suite PHPUnit, static analysis, `tsc --noEmit`, dan pre-commit audit).

---

## 6. Atomic Task Checklist

- [x] **Phase 1: Module Scaffolding & Database Migration**
  - [x] 1.1 Buat manifest modul `app/Modules/Inventory/module.json` kategori `resources`.
  - [x] 1.2 Buat file migrasi SQL: `2026_10_27_000023_create_inventory_tables.sql` (`inventory_units`, `item_categories`, `items`, `inventory_movements`).
  - [x] 1.3 Jalankan migrasi via `php aio migrate` dan siapkan auto-seeder unit ukuran.
- [x] **Phase 2: Domain Models & Contract Definition**
  - [x] 2.1 Buat interface kontrak `App\Modules\Inventory\Contracts\InventoryRegistryInterface`.
  - [x] 2.2 Buat domain model: `Item`, `InventoryUnit`, `ItemCategory`, `InventoryMovement`.
- [x] **Phase 3: Service Layer & Stock Movement Engine**
  - [x] 3.1 Implementasikan `App\Modules\Inventory\Services\InventoryService`.
  - [x] 3.2 Implementasikan logika pergerakan stok atomik (`in`, `out`, `adjustment`, `opname`) dan cek stok menipis.
  - [x] 3.3 Daftarkan service provider `InventoryServiceProvider` dan global helper `items()` & `inventory()`.
- [x] **Phase 4: Web UI & REST API Controllers**
  - [x] 4.1 Buat `InventoryController` untuk katalog item, penyesuaian stok, dan peringatan stok.
  - [x] 4.2 Buat `ApiInventoryController` untuk autocomplete pencarian dan pengecekan ketersediaan stok.
  - [x] 4.3 Daftarkan routes modul di `app/Modules/Inventory/routes.php`.
- [x] **Phase 5: Unit Testing & Self-Verification**
  - [x] 5.1 Buat unit test komprehensif di `tests/Unit/InventoryModuleTest.php`.
  - [x] 5.2 Perbarui `tests/Unit/ModuleSanitizationTest.php` untuk mengenali 11 modul.
  - [x] 5.3 Jalankan `./scripts/verify.ps1`, pastikan seluruh test lolos 100%.
  - [x] 5.4 Lakukan atomic commit dan merge branch fitur ke `main`.
  - [x] 5.5 Perbarui dokumentasi spec dan `SESSION_HANDOFF.md`.
