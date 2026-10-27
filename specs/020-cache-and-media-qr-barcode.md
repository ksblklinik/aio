# SPEC-020: High-Performance Tenant-Isolated Cache and Pure-PHP Media QR/Barcode Engine

- **Status**: `Completed`
- **Owner / Author**: Core Architecture Team
- **Tanggal Dibuat**: 2026-10-27
- **Target File / Modul**: `core/Cache/`, `core/Media/`, `core/Foundation/Application.php`, `core/helpers.php`

---

## 1. Problem Statement & Context
Aplikasi web modern memerlukan dua kapabilitas infrastruktur mendasar yang saat ini belum tersedia secara terstandarisasi di Core:
1. **Penyimpanan Cache Sementara (Transient Cache Engine)**:
   - Banyak modul bisnis membaca master data (kategori, opsi sistem, taxonomy) berulang kali ke database disk.
   - Tanpa cache terpadu dengan isolasi multi-tenant yang ketat, modul bisnis akan menambal caching lokal di memori atau file terpisah yang rawan kebocoran data antar-tenant (*cross-tenant data leak*).
2. **Generasi QR Code & Barcode Vektor (Media Engine)**:
   - Dokumen formal yayasan (SK Pengurus, Notulen, Kuitansi, Tanda Anggota) dan aplikasi lapangan (resi canvassing, SKU barang, tiket presensi) memerlukan kode verifikasi fisik berupa QR Code dan Barcode.
   - Pustaka pihak ketiga untuk QR Code umumnya berukuran besar (megabytes) atau menuntut ekstensi C (seperti GD atau ImageMagick). Dibutuhkan mesin murni PHP (*pure PHP*) yang menghasilkan SVG secara instan tanpa dependensi eksternal.

## 2. Goals & Non-Goals
### Goals
- [x] Menyediakan kontrak `CacheDriverInterface` dengan implementasi `ArrayCacheDriver`, `FileCacheDriver`, dan `SqliteCacheDriver`.
- [x] Menyediakan `CacheManager` dengan fitur *automatic tenant key prefixing* (`t_{tenantId}:{key}`), metode `get`, `put`, `remember`, `forget`, `flush`.
- [x] Menyediakan `Core\Media\QrCode` murni PHP yang menghasilkan elemen gambar vector `<svg>` (dan data URI) untuk URL verifikasi dan teks bebas.
- [x] Menyediakan `Core\Media\Barcode` standar linear Code-128 murni PHP dalam format `<svg>`.
- [x] Menyediakan helper global `cache()`, `qr_code()`, dan `barcode()`.
- [x] Seluruh kode murni PHP 8.3 tanpa dependensi eksternal atau ekstensi grafis C (`zero external dependencies`).

### Non-Goals
- Tidak mencakup konektor Redis terdistribusi cluster tingkat lanjut (tetap mendukung Redis dasar via driver interface jika diperlukan di kemudian hari).
- Tidak mencakup image manipulation kompleks seperti cropping, resizing foto JPEG (merupakan domain terpisah).

## 3. Technical Architecture & Design
### Alur Cache & Tenant Isolation
```
[Modul Bisnis]
       │
       ▼
   cache()->remember('kategori_surat', 3600, fn() => fetchFromDb())
       │
       ▼
[CacheManager: Prefixing dengan Tenant Context]
   Key aktual: "t_1:kategori_surat"
       │
   ┌───┴────────────────────────┐
   ▼                            ▼
[Cache Hit?]                 [Cache Miss?]
   │                            │
   ├──> Kembalikan data cached  └──> Eksekusi Callback ➔ Simpan ke Driver ➔ Return
```

### Media QR Code & Barcode Engine
```
Input Teks: "https://aio.org/verify/SK-2026-001"
              │
              ▼
    Core\Media\QrCode::svg($text, $size)
              │
              ▼
Matriks Boolean Matrix ➔ SVG Polygon/Rect Path Vector (XML string murni)
```

### Function Signatures
- `CacheDriverInterface`: `get(string $key, mixed $default = null): mixed`
- `CacheDriverInterface`: `put(string $key, mixed $value, int|null $ttl = null): bool`
- `CacheDriverInterface`: `has(string $key): bool`
- `CacheDriverInterface`: `forget(string $key): bool`
- `CacheDriverInterface`: `flush(): bool`
- `CacheManager`: `remember(string $key, int $ttl, callable $callback): mixed`
- `QrCode::svg(string $text, int $size = 200, string $color = '#000000', string $bgColor = '#ffffff'): string`
- `Barcode::svg(string $text, int $width = 300, int $height = 80, bool $showText = true): string`
- Global helper: `cache(?string $key = null, mixed $default = null)`
- Global helper: `qr_code(string $text, int $size = 200): string`
- Global helper: `barcode(string $text, int $width = 300, int $height = 80, bool $showText = true): string`

---

## 4. Edge Cases & Error Scenarios
- **Kasus 1: Cache Expiration**: Item dengan TTL yang sudah lewat waktu harus dianggap miss (`null`) dan otomatis dibersihkan.
- **Kasus 2: Tenant Isolation Leak**: Tenant 1 menyimpan `cache()->put('profil', 'A')`. Tenant 2 memanggil `cache()->get('profil')` $\rightarrow$ harus mengembalikan `null`.
- **Kasus 3: Input Barcode/QR Kosong**: Input string kosong atau karakter invalid harus ditangani secara aman tanpa fatal crash.
- **Kasus 4: File Permission / Concurrency**: File cache storage menggunakan atomic writes (`temp file + rename`) untuk mencegah race condition.

---

## 5. Test Strategy
- **Unit Test**: `tests/Unit/CoreCacheAndMediaTest.php`
  - Validasi operasi get, put, remember, forget pada `ArrayCacheDriver` dan `FileCacheDriver`.
  - Validasi isolasi tenant pada cache keys.
  - Validasi kadaluarsa TTL cache.
  - Validasi output `QrCode::svg()` memuat tag `<svg>` valid dan dimensi yang benar.
  - Validasi output `Barcode::svg()` memuat bar Code-128 dan teks label.
  - Validasi helpers global `cache()`, `qr_code()`, `barcode()`.

---

## 6. Atomic Task Checklist

- [x] **Phase 1: Cache Architecture & Drivers**
  - [x] 1.1 Buat `Core\Cache\CacheDriverInterface`.
  - [x] 1.2 Buat `Core\Cache\ArrayCacheDriver`.
  - [x] 1.3 Buat `Core\Cache\FileCacheDriver` (atomic write & TTL check).
  - [x] 1.4 Buat `Core\Cache\CacheManager` dengan isolasi tenant dan metode `remember()`.
- [x] **Phase 2: Media QR Code & Barcode Engine**
  - [x] 2.1 Buat `Core\Media\QrCode` (Pure-PHP SVG QR Code Generator).
  - [x] 2.2 Buat `Core\Media\Barcode` (Pure-PHP Code128 Linear Barcode SVG Generator).
  - [x] 2.3 Daftarkan lazy singleton di `core/Foundation/Application.php` dan global helpers di `core/helpers.php`.
- [x] **Phase 3: Unit Testing & TDD Verification**
  - [x] 3.1 Buat `tests/Unit/CoreCacheAndMediaTest.php`.
  - [x] 3.2 Uji fungsionalitas caching, TTL, dan tenant isolation.
  - [x] 3.3 Uji generasi SVG QR Code dan Code128 Barcode.
- [x] **Phase 4: Verification & Git Integration**
  - [x] 4.1 Jalankan `pwsh -File ./scripts/verify.ps1`.
  - [x] 4.2 Lakukan atomic commit di branch `feat/020-cache-and-media-qr-barcode`.
  - [x] 4.3 Merge ke `main` dengan `--no-ff`.
  - [x] 4.4 Perbarui `specs/README.md` dan `.agents/SESSION_HANDOFF.md`.
