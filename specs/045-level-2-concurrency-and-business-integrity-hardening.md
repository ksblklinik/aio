# SPEC-045: Level 2 Concurrency & Business Integrity Hardening

- **Status**: `Completed`
- **Owner / Author**: Antigravity & Lead Architect
- **Tanggal Dibuat**: 2026-10-28
- **Tanggal Diselesaikan**: 2026-10-28
- **Target File / Modul**:
  - `app/Modules/Inventory/Services/InventoryService.php`
  - `app/Modules/Billing/Services/BillingService.php`
  - `app/Modules/Schedule/Services/ScheduleService.php`
  - `app/Modules/Finance/Services/FinanceService.php`
  - `app/Modules/Tariff/Services/TariffService.php`
  - `core/Sequence/SequenceManager.php`

---

## 1. Problem Statement & Context
Audit mendalam terhadap modul fondasi bersama Level 2 (`Inventory`, `Billing`, `Schedule`, `Finance`, `Tariff`) dan komponen `SequenceManager` mengungkap 6 celah konkurensi dan integritas bisnis berisiko tinggi:
1. **L2-01 (Inventory Lost Updates & Lock Bypass)**: `recordStockMovement` menghitung sisa stok di memori PHP lalu menimpa baris via `UPDATE items SET current_stock = ?`, menyebabkan hilangnya update stok (*lost update*) dan *overselling* saat dua kasir/petugas mendispensasi stok bersamaan. Selain itu, blok `lock()->run` pada `deductStockFefo` menelan `Throwable` sehingga mengeksekusi ulang callback dan mengabaikan timeout lock.
2. **L2-02 (Billing Parameter Inversion & Over-Payment)**: `BillingService::deductItemsStock` membalik argumen pemanggilan `deductStockFefo` (`notes`, `refType`, `refId`). Pada `recordPayment`, pembacaan sisa tagihan dilakukan di luar transaksi sehingga pembayaran konkuren dapat menyebabkan over-payment dan inkonsistensi saldo invoice vs ledger. Saat invoice dibatalkan (`cancelInvoice`), jasa medis di `tariff_fee_allocations` tertinggal berstatus `accrued` (terutang).
3. **L2-03 (Schedule Doctor Call Collision & Ticket Duplication)**: `callNextTicket()` memilih tiket antrean pertama lalu mengupdate `status = 'called'` tanpa filter `AND status = 'waiting'`. Akibatnya, dua dokter yang menekan tombol panggil di detik yang sama akan memanggil pasien yang sama ke dua poli berbeda.
4. **L2-04 (Finance Silent Overdraft)**: `recordOutflow()` memeriksa saldo akun sebelum transaksi, namun query update saldo tidak memiliki guard `AND current_balance >= ?`, memungkinkan penarikan dana konkuren menghasilkan saldo kas negatif.
5. **L2-05 (Tariff Split Invoicing Idempotency)**: `recordInvoiceSplits` tidak memeriksa apakah fee allocation untuk invoice tersebut sudah pernah dibukukan, berpotensi menggandakan jasa dokter jika invoice diproses ulang.
6. **L2-06 (Sequence Mutex Bypass)**: `SequenceManager::next()` menangkap `Throwable` (termasuk `LockTimeoutException`) dan tetap menjalankan callback secara konkuren tanpa lock.

---

## 2. Goals & Non-Goals
### Goals
- Seluruh pemotongan stok (`Inventory` & multi-warehouse) menggunakan operasi atomik SQL bersyarat (`WHERE current_stock >= ?`), mencegah saldo negatif dan *lost updates*.
- Penanganan lock `lock()->run` diperbaiki di seluruh modul: tidak menelan exception bisnis callback dan tidak membypass eksekusi saat timeout.
- Pembayaran invoice (`recordPayment`) diverifikasi ulang di dalam transaksi dengan atomic conditional update.
- Pembatalan invoice (`cancelInvoice`) otomatis membatalkan alokasi jasa medis (`tariff_fee_allocations`) terkait.
- Pemanggilan antrean (`callNextTicket`) menggunakan klaim optimistik `WHERE id = ? AND status = 'waiting'`, memastikan satu tiket hanya dapat dipanggil oleh satu poli.
- Pengeluaran kas (`recordOutflow`) menerapkan guard atomik `AND current_balance >= ?`.

### Non-Goals
- Modifikasi UI frontend React (semua kontrak API tetap 100% kompatibel).
- Penambahan tabel migrasi baru jika fungsionalitas dapat diamankan secara atomik pada skema yang ada.

---

## 3. Technical Design
1. **Lock Execution Protocol**:
   Ketika memanggil `lock()->run($key, $callback, $ttl, $timeout)`:
   - Jika melempar `LockTimeoutException`: lemparkan `RuntimeException` yang jelas ("Operasi sedang diproses oleh permintaan lain, silakan coba beberapa saat lagi"). Jangan pernah jatuh kembali (*fallback*) mengeksekusi callback tanpa lock!
   - Jika callback sendiri melempar `Throwable`: rethrow segera agar tidak dieksekusi ulang.
   - Fallback hanya berlaku jika class `LockManagerInterface` tidak terikat sama sekali di kontainer (lingkungan bootstrap minimal).
2. **Atomic Inventory Decrement**:
   Pada `recordStockMovement` (tipe `out`):
   ```sql
   UPDATE items 
   SET current_stock = round(current_stock - ?, 4), updated_at = ? 
   WHERE id = ? AND tenant_id = ? AND current_stock >= ?
   ```
   Jika `affectingStatement === 0`, lemparkan `RuntimeException("Stok tidak mencukupi untuk pengeluaran ini.")`.
   Demikian pula pada `inventory_stock_locations`:
   ```sql
   UPDATE inventory_stock_locations 
   SET quantity = round(quantity - ?, 4), updated_at = ? 
   WHERE id = ? AND quantity >= ?
   ```
3. **Billing Argument & Payment Safety**:
   - Panggil `deductStockFefo($itemId, $qty, $roomId, $notes, 'invoice', (string)$invoiceId)`.
   - Di `recordPayment`: di dalam transaksi, baca ulang status terkini invoice via `SELECT * FROM invoices WHERE id = ? AND tenant_id = ?`. Verifikasi `$latestInvoice['payment_status'] !== 'paid'` dan `amount_due >= $amount`. Update atomik:
     ```sql
     UPDATE invoices SET amount_paid = amount_paid + ?, amount_due = grand_total - (amount_paid + ?), ...
     WHERE id = ? AND tenant_id = ? AND amount_due >= ?
     ```
   - Di `cancelInvoice`: jalankan `UPDATE tariff_fee_allocations SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP WHERE invoice_id = ? AND tenant_id = ? AND status = 'accrued'`.
4. **Schedule Queue Atomic Call**:
   Di `callNextTicket`:
   ```sql
   UPDATE queue_tickets 
   SET status = 'called', called_at = ?, call_count = call_count + 1, room_id = ?, ...
   WHERE id = ? AND tenant_id = ? AND status = 'waiting'
   ```
   Jika row count === 0 (sudah diambil dokter lain), ulangi pencarian tiket berikutnya dalam loop kecil (maksimal 3 kali).

---

## 4. Atomic Task Checklist
- [x] **Phase 1: Concurrency Mutex & Sequence Fixes (L2-01, L2-06)**
  - [x] 1.1 Perbaiki error handling `lock()->run` pada `SequenceManager.php`.
  - [x] 1.2 Perbaiki error handling `lock()->run` pada `InventoryService.php` (`deductStockFefo`) dan `ScheduleService.php`.
- [x] **Phase 2: Inventory Atomic Stock & Multi-Warehouse Guard (L2-01)**
  - [x] 2.1 Update `recordStockMovement()` dengan atomic conditional update (`current_stock >= ?`).
  - [x] 2.2 Update `inventory_stock_locations` dengan atomic conditional decrement.
  - [x] 2.3 Perbaiki parameter order pada pemanggilan `deductStockFefo()` di `BillingService.php`.
- [x] **Phase 3: Billing & Tariff Integrity Hardening (L2-02, L2-05)**
  - [x] 3.1 Hardening `BillingService::recordPayment()` (re-query di dalam transaksi + atomic due update).
  - [x] 3.2 Update `cancelInvoice()` untuk membatalkan `tariff_fee_allocations` berstatus accrued.
  - [x] 3.3 Tambahkan proteksi idempotensi pada `TariffService::recordInvoiceSplits()`.
- [x] **Phase 4: Schedule Queue & Finance Balance Safety (L2-03, L2-04)**
  - [x] 4.1 Update `ScheduleService::callNextTicket()` dengan klaim atomik `status = 'waiting'` anti tabrakan dokter.
  - [x] 4.2 Update `FinanceService::recordOutflow()` dengan atomic check `AND current_balance >= ?`.
- [x] **Phase 5: Comprehensive Unit & Concurrency Tests**
  - [x] 5.1 Buat test suite `tests/Unit/Level2ConcurrencyIntegrityTest.php` memverifikasi seluruh celah L2.
  - [x] 5.2 Jalankan full PHPUnit suite & typecheck.
  - [x] 5.3 Commit & merge ke `main`.
