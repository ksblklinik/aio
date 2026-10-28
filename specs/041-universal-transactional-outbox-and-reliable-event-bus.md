# SPEC-041: Universal Transactional Outbox Pattern & Reliable Event Bus Engine

- **Status**: `Completed`
- **Owner / Author**: Antigravity & Lead Architect
- **Tanggal Dibuat**: 2026-10-28
- **Target File / Modul**: `core/Events`, `core/Outbox`, `database/migrations`, `core/Console/Commands`, `core/Foundation/Application.php`, `core/helpers.php`

---

## 1. Problem Statement & Context
Dalam arsitektur SaaS modular universal, transaksi bisnis sering kali memicu *side effects* ke subsistem lain:
1. Pasien membayar tagihan $\rightarrow$ Faktur berstatus `paid` $\rightarrow$ Kirim push SSE ke monitor kasir $\rightarrow$ Pancarkan Webhook ke payment gateway $\rightarrow$ Enqueue background job cetak struk $\rightarrow$ Catat audit log.
2. Obat dikeluarkan dari depo $\rightarrow$ Stok barang berkurang $\rightarrow$ Broadcast event peringatan stok menipis via SSE $\rightarrow$ Picu auto-reorder job.

Dalam implementasi naif, aplikasi melakukan *Dual-Write*: menulis ke basis data lalu langsung memanggil broadcast/webhook/queue. Pola ini rentan terhadap dua bencana konsistensi data (*data consistency disaster*):
- **Phantom Write (Event Hilang)**:
  Transaksi database berhasil di-commit, namun server/PHP mati mendadak, jaringan terputus, atau worker crash sebelum event berhasil dikirim. Event hilang selamanya dan modul lain tidak pernah tahu transaksi telah terjadi.
- **Ghost Action (Aksi Siluman)**:
  Event dipancarkan terlebih dahulu sebelum transaksi database selesai. Jika transaksi database kemudian gagal (*rollback*) karena validasi atau foreign key, subsistem lain (misal: webhook eksternal atau SSE client) telah menerima kabar bahwa transaksi sukses, padahal datanya tidak pernah ada di database.

**Solusi Arsitektural**: Menerapkan **Transactional Outbox Pattern** murni berbasis PHP dan SQLite lokal:
Setiap event bisnis disimpan ke tabel `outbox_events` di dalam **transaksi database yang sama persis** dengan perubahan data bisnis. Dengan jaminan ACID SQLite, event dijamin 100% tersimpan jika transaksi commit, dan 100% hilang jika transaksi rollback. Selanjutnya, komponen **Outbox Relay** mempublikasikan event ke SSE, Webhook, Queue, dan Event Listeners dengan jaminan pengiriman *At-Least-Once Delivery* dan *Idempotency Key*.

---

## 2. Goals & Non-Goals

### Goals
- [x] Menyediakan tabel basis data `outbox_events` untuk persistensi event atomik di dalam transaksi lokal.
- [x] Menyediakan kontrak `Core\Outbox\TransactionalOutboxManagerInterface` dan implementasi `Core\Outbox\TransactionalOutboxManager`.
- [x] Menjamin zero dual-write anomaly: Event hanya tersimpan jika transaksi bisnis commit.
- [x] Menyediakan mekanisme **Outbox Relay**:
  - Relay batch otomatis dengan kontrol konkurensi (atomic lock) agar aman dijalankan dari multiple worker/cron.
  - Dispatch terintegrasi ke 3 target:
    1. **Domain Event Listeners** (`Core\Events\EventDispatcher`)
    2. **Real-time SSE Push Bus** (`Core\Broadcasting\SseBroadcasterInterface`)
    3. **Outbound Webhooks** (`Core\Webhook\WebhookDispatcher`)
  - Exponential backoff dan pelacakan kegagalan dengan batas percobaan (*max attempts*).
  - Status event: `pending`, `published`, `failed`.
- [x] Menyediakan perintah CLI konsol:
  - `php aio outbox:relay [--batch=50]`: Mengalirkan event tertunda ke seluruh penerima.
  - `php aio outbox:drain`: Mengosongkan seluruh antrean outbox hingga selesai.
  - `php aio outbox:status`: Tampilkan statistik event pending, published, dan failed.
  - `php aio outbox:prune [--days=7] [--force]`: Bersihkan event lama yang sudah berstatus `published`.
- [x] Menyediakan helper global:
  - `outbox(): TransactionalOutboxManagerInterface`.

### Non-Goals
- Fitur ini tidak menggantikan modul antrean jangka panjang (`jobs`), melainkan menjamin bahwa pemancaran event dan enqueue job dari dalam transaksi database tidak akan pernah hilang atau menjadi ghost.

---

## 3. Technical Architecture & Design

### Alur Kerja Transactional Outbox
```
┌─────────────────────────────────────────────────────────────┐
│                 BEGIN TRANSACTION (ACID)                    │
├─────────────────────────────────────────────────────────────┤
│ 1. INSERT INTO invoices (grand_total, status) VALUES (...)  │
│ 2. INSERT INTO inventory_movements (quantity) VALUES (...)  │
│ 3. INSERT INTO outbox_events (event_id, event_name, ...)    │
├─────────────────────────────────────────────────────────────┤
│                    COMMIT TRANSACTION                       │
└──────────────────────────────┬──────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────┐
│              Outbox Relay Worker (php aio outbox:relay)     │
├─────────────────────────────────────────────────────────────┤
│  SELECT * FROM outbox_events WHERE status = 'pending'       │
│  WITH ATOMIC LOCK 'outbox:relay'                            │
├──────────────────────────────┬──────────────────────────────┤
│                              │                              │
▼                              ▼                              ▼
[SSE Broadcaster]     [Webhook Dispatcher]        [Domain Listeners]
(Monitor Kasir)       (Third-Party API)           (Internal Queues)
│                              │                              │
└──────────────────────────────┼──────────────────────────────┘
                               │
                               ▼
               UPDATE outbox_events SET status = 'published'
```

### Data Model: `outbox_events`
```sql
CREATE TABLE IF NOT EXISTS outbox_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NULL,
    event_id TEXT NOT NULL UNIQUE,
    event_name TEXT NOT NULL,
    payload TEXT NOT NULL,
    target_channel TEXT NULL,
    status TEXT NOT NULL DEFAULT 'pending', -- 'pending', 'published', 'failed'
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 5,
    last_error TEXT NULL,
    published_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_outbox_status_created ON outbox_events(status, created_at);
CREATE INDEX IF NOT EXISTS idx_outbox_tenant_status ON outbox_events(tenant_id, status);
```

---

## 4. Atomic Task Checklist

- [x] **Phase 1: Database Migration & Contracts**
  - [x] 1.1 Buat migrasi `database/migrations/2026_10_28_000035_create_outbox_events_table.sql`.
  - [x] 1.2 Jalankan migrasi via `php aio migrate`.
  - [x] 1.3 Buat interface `Core\Outbox\TransactionalOutboxManagerInterface`.
  - [x] 1.4 Buat DTO `Core\Outbox\OutboxMessage`.

- [x] **Phase 2: Outbox Engine Implementation**
  - [x] 2.1 Buat implementasi `Core\Outbox\TransactionalOutboxManager`.
  - [x] 2.2 Integrasikan relay dengan `EventDispatcher`, `SseBroadcaster`, dan `WebhookDispatcher`.
  - [x] 2.3 Tambahkan helper global `outbox()`.
  - [x] 2.4 Daftarkan bindings di `Core\Foundation\Application.php`.

- [x] **Phase 3: CLI Console Tooling**
  - [x] 3.1 Buat perintah `php aio outbox:relay` (`core/Console/Commands/OutboxRelayCommand.php`).
  - [x] 3.2 Buat perintah `php aio outbox:drain` (`core/Console/Commands/OutboxDrainCommand.php`).
  - [x] 3.3 Buat perintah `php aio outbox:status` (`core/Console/Commands/OutboxStatusCommand.php`).
  - [x] 3.4 Buat perintah `php aio outbox:prune` (`core/Console/Commands/OutboxPruneCommand.php`).
  - [x] 3.5 Daftarkan perintah CLI di `Application::createCliKernel()`.

- [x] **Phase 4: Unit Testing & Self-Verification**
  - [x] 4.1 Tulis unit test komprehensif `tests/Unit/TransactionalOutboxTest.php` (atomic rollback, idempotency, multi-channel relay, max retry, purge).
  - [x] 4.2 Jalankan PHPUnit test suite global dan pastikan 100% lolos.
  - [x] 4.3 Jalankan TypeScript typecheck (`npm run typecheck`) dan Vite build (`npm run build`).
  - [x] 4.4 Perbarui status dokumen spec ini menjadi `Completed`.
  - [x] 4.5 Perbarui `specs/README.md` dan `.agents/SESSION_HANDOFF.md`.
  - [x] 4.6 Buat atomic Git commit dan merge branch ke `main`.
