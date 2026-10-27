# SPEC-018: Geolocation Engine, Atomic Financial Ledger, and Idempotency Guard

- **Status**: `Completed`
- **Owner / Author**: Antigravity & Lead Architect
- **Tanggal Dibuat**: 2026-10-27
- **Target File / Modul**: `core/Geo`, `core/Ledger`, `core/Middleware`, `core/helpers.php`

---

## 1. Problem Statement & Context
Modul bisnis lapangan dan logistik mobilitas (seperti **Sales Canvassing**, **Field Force**, **Agregator Driver Fleet Pooling**, dan **On-demand Delivery**) membutuhkan tiga kapabilitas infrastruktur krusial:
1. **Geolocation & Geofencing Math**: Perhitungan jarak tempuh antrean driver (Haversine Formula) dan validasi radius lokasi check-in toko (mencegah absen/order palsu). Karena SQLite tidak memiliki PostGIS native, Core harus menyediakan kalkulasi geografis presisi tinggi.
2. **Atomic Ledger & Balance Mutation**: Transaksi saldo dompet driver, piutang toko, komisi platform, dan mutasi kas memerlukan pencatatan *Double-Entry* anti-race condition (mencegah bug *lost update* dan saldo minus jika order masuk bersamaan).
3. **Idempotency Guard**: Pengiriman data dari sales di daerah pedalaman (*blank spot*) atau koneksi internet driver yang tidak stabil sering mengirim ulang request yang sama (retry). Tanpa proteksi idempotency key, transaksi dapat tercatat ganda.

### Mitigasi Performa Platform (Zero Bloat & Lazy Loading):
- **On-Demand Loading**: Layanan Geo adalah pure static utility yang tidak memakan koneksi DB atau RAM jika tidak dipanggil.
- **Route-Scoped Middleware**: Idempotency guard hanya diaktifkan pada endpoint rute yang diberi label `->middleware('idempotent')`, tidak membebani rute biasa.
- **Storage Terkontrol**: Penyimpanan cache idempotency menggunakan file-backed TTL mandiri di `storage/framework/idempotency/` tanpa ketergantungan Redis.

---

## 2. Goals & Non-Goals

### Goals
- [x] Menyediakan **`Core\Geo\Geo`** dengan method `distance()`, `inRadius()`, dan `bearing()`.
- [x] Menyediakan helper global `geo_distance()` dan `geo_in_radius()`.
- [x] Menyediakan tabel migrasi `ledgers` dan `ledger_entries` untuk audit pembukuan saldo aman.
- [x] Menyediakan **`Core\Ledger\LedgerManager`** untuk mutasi debit/kredit saldo atomik terisolasi tenant (`record()`, `getBalance()`, `getStatement()`).
- [x] Menyediakan helper global `ledger()`.
- [x] Menyediakan middleware **`Core\Middleware\IdempotentRequest`** dengan header `X-Idempotency-Key` berbatas waktu (TTL).
- [x] Mendaftarkan middleware `idempotent` di pipeline router.
- [x] 100% test coverage melalui PHPUnit.

### Non-Goals
- Tidak mewajibkan instalasi ekstensi C pihak ketiga (seperti GeoIP binary atau PostgreSQL PostGIS).
- Tidak mengunci modul bisnis pada skema akun tunggal; ledger dirancang fleksibel untuk akun dompet (`wallet`), piutang (`receivable`), maupun kas (`cash`).

---

## 3. Technical Architecture & Design

### Data Flow
```
[Client / Mobile App]
       │
       │ (Headers: X-Idempotency-Key: abc-123)
       ▼
[IdempotentRequest Middleware] ─── (Key exists & completed?) ───> Return Cached Response
       │ (Not exists)
       ▼
[Controller / Business Module]
       │
       ├──> Geo::inRadius(checkLat, checkLon, outletLat, outletLon, 100m) ──> Geofence OK
       │
       └──> ledger()->record($account, $amount, 'credit', ...) ──> Atomic DB Transaction
```

### Data Schema
Tabel migrasi baru: `database/migrations/2026_10_27_000016_create_ledger_tables.sql`:
```sql
-- @up
CREATE TABLE IF NOT EXISTS ledgers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    account_type TEXT NOT NULL,
    account_id TEXT NOT NULL,
    currency TEXT NOT NULL DEFAULT 'IDR',
    balance REAL NOT NULL DEFAULT 0.0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(tenant_id, account_type, account_id)
);
CREATE INDEX IF NOT EXISTS idx_ledgers_tenant_acc ON ledgers(tenant_id, account_type, account_id);

CREATE TABLE IF NOT EXISTS ledger_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    ledger_id INTEGER NOT NULL,
    direction TEXT NOT NULL, -- 'debit' | 'credit'
    amount REAL NOT NULL,
    balance_after REAL NOT NULL,
    reference_type TEXT,
    reference_id TEXT,
    description TEXT,
    metadata TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(ledger_id) REFERENCES ledgers(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_ledger_entries_tenant_ref ON ledger_entries(tenant_id, reference_type, reference_id);

-- @down
DROP INDEX IF EXISTS idx_ledger_entries_tenant_ref;
DROP TABLE IF EXISTS ledger_entries;
DROP INDEX IF EXISTS idx_ledgers_tenant_acc;
DROP TABLE IF EXISTS ledgers;
```

---

## 4. Edge Cases & Error Scenarios
- **Invalid Geolocation Coordinates**: Koordinat latitude di luar [-90, 90] atau longitude di luar [-180, 180] $\rightarrow$ Lempar `InvalidArgumentException`.
- **Negative Balance Guard**: Opsi `allowNegative: false` pada ledger menolak pemotongan jika saldo tidak mencukupi, melempar `RuntimeException("Insufficient balance")`.
- **Concurrent Request Race Condition**: Idempotency middleware mengunci key dengan status `in_progress` sehingga request retry yang datang di milidetik bersamaan akan ditahan atau ditolak hingga selesai.

---

## 5. Test Strategy
- **GeoTest**: Menguji kalkulasi jarak Haversine (akurasi dalam km dan meter) dan evaluasi geofence radius.
- **LedgerTest**: Menguji mutasi saldo debit/kredit, balance after calculation, pembukuan statement, dan pencegahan saldo negatif.
- **IdempotencyTest**: Menguji penyimpanan respon, pengembalian respon cache saat request diulang dengan key yang sama, dan bypass saat header tidak disertakan.

---

## 6. Atomic Task Checklist

- [x] **Phase 1: Geolocation Math Engine**
  - [x] 1.1 Buat class `Core\Geo\Geo` dengan rumus Haversine (`distance`, `inRadius`, `bearing`).
  - [x] 1.2 Buat helper global `geo_distance()` dan `geo_in_radius()` di `core/helpers.php`.

- [x] **Phase 2: Atomic Financial Ledger Engine**
  - [x] 2.1 Buat migrasi `database/migrations/2026_10_27_000016_create_ledger_tables.sql`.
  - [x] 2.2 Buat class `Core\Ledger\LedgerManager` dengan operasi atomik transaksional.
  - [x] 2.3 Buat helper global `ledger()` di `core/helpers.php`.
  - [x] 2.4 Daftarkan `LedgerManager` di `core/Foundation/Application.php`.

- [x] **Phase 3: Idempotency Guard Middleware**
  - [x] 3.1 Buat middleware `Core\Middleware\IdempotentRequest` berbasis storage file cache TTL.
  - [x] 3.2 Registrasikan alias middleware `idempotent` di `Core\Router` dan `Application.php`.

- [x] **Phase 4: Comprehensive TDD & Verification**
  - [x] 4.1 Buat test suite di `tests/Unit/CoreGeoLedgerAndIdempotencyTest.php`.
  - [x] 4.2 Jalankan PHPUnit test suite (target: 100% PASS).
  - [x] 4.3 Jalankan `./scripts/verify.ps1`, update checklist, dan merge ke `main`.

