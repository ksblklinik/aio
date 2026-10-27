# SPEC-029B: Inventory Multi-Warehouse, Stock Transfer, Batch & Expiry Engine

- **Status**: `Draft`
- **Owner / Author**: Antigravity Platform Team
- **Tanggal Dibuat**: 2026-10-28
- **Target Modul**: `app/Modules/Inventory/`
- **Tingkat Arsitektur**: `Level 2 (Shared Business Foundation - Enhancement)`

---

## 1. Problem Statement & Context
Pada SPEC-029 terdahulu, inventori telah berhasil mengelola katalog barang fisik (`goods`), jasa (`service`), satuan ukuran standar (UOM), dan mutasi pergerakan stok dasar (`in`, `out`, `adj`, `opname`). Namun untuk kebutuhan organisasi yang lebih kompleks (seperti Fasilitas Kesehatan / Farmasi, Logistik Bantuan Bencana NGO, dan Retail Multi-Gudang):
1. **Ketiadaan Lokasi Penyimpanan Bersekat (*Single-Location Limitation*)**: Saldo stok saat ini hanya tersimpan secara agregat tunggal per tenant (`items.current_stock`). Padahal klinik memiliki *Gudang Induk Farmasi vs Apotek Rawat Jalan vs Ruang Tindakan IGD*; NGO memiliki *Gudang Logistik Pusat vs Posko Lapangan Bencana*.
2. **Ketiadaan Pelacakan Nomor Batch / Lot & Tanggal Kadaluarsa (*Expiry Date*)**: Obat-obatan medis, reagen laboratorium, dan bahan pangan bantuan bencana wajib memiliki identitas nomor batch pabrik dan tanggal kadaluarsa. Tanpa ini, organisasi tidak dapat melakukan *Batch Recall* (penarikan produk bermasalah) dan rentan mendistribusikan obat/makanan kadaluarsa.
3. **Ketiadaan Metode Alokasi FEFO (*First-Expired, First-Out*)**: Tanpa pelacakan tanggal kadaluarsa di tingkat batch, sistem tidak dapat memandu petugas apotek/gudang untuk mengeluarkan stok yang paling cepat kadaluarsa terlebih dahulu, menyebabkan pemborosan biaya akibat barang rusak/expired.
4. **Kebutuhan Transfer Stok Antar-Gudang (*Inter-Warehouse Transfer*)**: Diperlukan mekanisme mutasi perpindahan stok formal antar-ruangan/gudang penyimpanan.

Solusinya adalah memperkuat modul `Inventory` melalui **SPEC-029B** dengan menambahkan dukungan multi-gudang (terintegrasi dengan `facilities_rooms` dari SPEC-031), pelacakan nomor batch & expiry date, metode pemotongan FEFO, dan transfer stok antar-lokasi.

---

## 2. Goals & Non-Goals

### Goals
- **Pelacakan Stok Multi-Gudang / Multi-Ruangan (`inventory_stock_locations`)**:
  - Saldo stok fisik dicatat terperinci per ruangan penyimpanan (`room_id` merujuk ke ruangan bertipe `storage` di SPEC-031).
  - Agregasi `items.current_stock` tetap sinkron sebagai total keseluruhan stok di seluruh gudang tenant.
- **Manajemen Nomor Batch & Tanggal Kadaluarsa (`item_batches`)**:
  - Setiap penerimaan barang (`in`) dapat mencatat `batch_number`, `expiry_date`, dan status batch (`active`, `quarantined`, `expired`).
  - Peringatan dini obat/barang yang mendekati tanggal kadaluarsa (*Near-Expiry Alert* e.g., dalam 30, 60, atau 90 hari).
- **Mutasi Transfer Stok Antar-Gudang (`transfer`)**:
  - Mutasi resmi perpindahan stok dari Gudang Asal ke Gudang Tujuan.
  - Penomoran transfer otomatis via sequence: `TRF/2026/00001`.
  - Mengurangi stok di gudang asal dan menambah stok di gudang tujuan dalam satu transaksi database atomik.
- **Metode Alokasi Stok FEFO (*First-Expired, First-Out*)**:
  - Saat penjualan atau pemotongan resep obat terjadi via `deductStock()`, sistem secara cerdas memotong batch aktif yang memiliki tanggal kadaluarsa paling awal (*earliest expiry date*).
- **Pembaruan Shared Contract Interface (`InventoryRegistryInterface`)**:
  - Penambahan method: `transferStock()`, `listBatches()`, `createBatch()`, `listNearExpiryItems()`, `getStockByLocation()`.

### Non-Goals
- Tidak mencakup sistem multi-zona otomatisasi robot conveyor pergudangan cerdas (ASRS).

---

## 3. Technical Architecture & Design

### Relasi Data Multi-Gudang & Batch
```
┌────────────────────────────────────────────────────────────────────────┐
│                                 items                                  │
├────────────────────────────────────────────────────────────────────────┤
│ id, tenant_id, code, name, type, current_stock (agregat total)         │
└──────────────────┬─────────────────────────────────┬───────────────────┘
                   │ 1:N                             │ 1:N
                   ▼                                 ▼
┌────────────────────────────────────┐ ┌─────────────────────────────────┐
│            item_batches            │ │   facilities_rooms (SPEC-031)   │
├────────────────────────────────────┤ ├─────────────────────────────────┤
│ id, tenant_id, item_id             │ │ id, tenant_id, name, type       │
│ batch_number (varchar)             │ │ (e.g. Gudang Farmasi, Apotek RJ)│
│ expiry_date (date/varchar)         │ └────────────────┬────────────────┘
│ status ('active'|'quarantine'|'exp')                  │ 1:N
└──────────────────┬─────────────────┘                  │
                   │                                    │
                   └─────────────────┬──────────────────┘
                                     ▼
┌────────────────────────────────────────────────────────────────────────┐
│                      inventory_stock_locations                         │
├────────────────────────────────────────────────────────────────────────┤
│ id (PK, int)                                                           │
│ tenant_id (FK, int)                                                    │
│ item_id (FK items)                                                     │
│ room_id (FK facilities_rooms)                                          │
│ batch_id (FK item_batches, nullable)                                   │
│ quantity (decimal/float, default 0)                                    │
│ created_at, updated_at                                                 │
└────────────────────────────────────────────────────────────────────────┘
```

---

## 4. Atomic Task Checklist

- [ ] **Phase 1: Database Migrations & Models**
  - [ ] 1.1 Buat migration `database/migrations/2026_10_28_000027_add_multi_warehouse_and_batches.sql` untuk tabel `item_batches` dan `inventory_stock_locations`.
  - [ ] 1.2 Jalankan migrasi via console runner dan verifikasi skema database.
- [ ] **Phase 2: Service Contracts & Core Engine Implementation (TDD)**
  - [ ] 2.1 Perbarui interface `App\Modules\Inventory\Contracts\InventoryRegistryInterface`.
  - [ ] 2.2 Tulis test suite `tests/Unit/InventoryMultiWarehouseTest.php` mencakup batch tracking, FEFO deduction, transfer stok antar gudang, dan near-expiry alert.
  - [ ] 2.3 Implementasikan logika multi-lokasi & FEFO di `InventoryService` hingga seluruh unit test lolos 100%.
- [ ] **Phase 3: Integration & UI Views**
  - [ ] 3.1 Perbarui Web Controller dan REST API Inventory untuk mendukung filter per gudang dan transfer form.
  - [ ] 3.2 Verifikasi integrasi pemotongan stok otomatis di modul `Billing` agar mendukung pemotongan per-ruangan/gudang.
- [ ] **Phase 4: Final Verification, Review & Handoff**
  - [ ] 4.1 Jalankan seluruh test suite global via `vendor/bin/phpunit.bat` (memastikan tidak ada regresi).
  - [ ] 4.2 Jalankan `npm run typecheck` dan script `scripts/verify.ps1`.
  - [ ] 4.3 Perbarui `specs/README.md` dan `.agents/SESSION_HANDOFF.md`.
  - [ ] 4.4 Merge atomic commit ke branch `main`.
