# SPEC-044: Core Security & Resilience Gap Remediation

- **Status**: `Completed`
- **Owner / Author**: Antigravity & Lead Architect
- **Tanggal Dibuat**: 2026-10-28
- **Tanggal Selesai**: 2026-10-28
- **Target File / Modul**: `core/Security`, `core/Database/Connection.php`, `core/Outbox`, `core/Privacy`, `core/Diagnostics`, `core/Backup`, `core/Audit`, `.gitignore`

---

## 1. Problem Statement & Context
Audit menyeluruh (lihat laporan `core_gap_audit.md`) menemukan 14 celah terverifikasi (G-01..G-14) pada Core dan modul shared, termasuk regresi dari SPEC-041..043. Celah paling berbahaya: `APP_KEY` kosong dipakai diam-diam oleh seluruh subsistem kriptografi, backup/ekspor PII tidak di-gitignore, anonimisasi crash pada skema asli, dan relay outbox dapat mengirim event ganda.

## 2. Goals & Non-Goals
### Goals
- Satu sumber kebenaran kunci aplikasi (`Core\Security\AppKey`) dengan fail-fast di production.
- Shredder/anonymizer/exporter schema-aware (dinamis), tidak menelan error, tanpa orphan PII.
- Relay outbox exactly-once-claim (optimistic claim) antar proses.
- Transaksi manual `Connection` kompatibel dengan nested `transaction()`.
- Benchmark aman (try/finally), artefak sensitif tidak bisa ter-commit.

### Non-Goals (butuh keputusan / spec lanjutan)
- G-05 pemisahan penyimpanan kunci tenant dari backup (key escrow) — menunggu keputusan arsitektur.
- G-11, G-13, G-14 — spec lanjutan (persisted slow-query log, test berbasis migrasi asli, isolasi container test).
- Regenerasi `APP_KEY` di `.env` lokal — menunggu persetujuan (menginvalidasi hash-chain audit & seal lokal).

## 3. Technical Design
- `AppKey::get()`: baca `$_ENV`/`config('app.key')`. Kosong/<16 byte → `production`: lempar `InsecureAppKeyException`; non-production: kunci dev berlabel insecure + `AppKey::isInsecure()` dilaporkan watchdog. Perintah `php aio key:generate [--show] [--force]`.
- `Connection::beginTransaction/commit/rollBack` ikut `transactionLevel` (SAVEPOINT bila nested).
- Outbox: lock dipegang proses lain → skip; setiap event di-claim via `UPDATE ... WHERE id=? AND status='pending' AND attempts=?` (rowCount==1) sebelum dispatch.
- Shredder: discovery tabel `tenant_id` via `sqlite_master` & `PRAGMA table_info`; `PRAGMA defer_foreign_keys=ON` dalam transaksi (FK tetap ON → CASCADE membersihkan tabel anak; leaf tables dipurged eksplisit); scrub kolom PII hanya yang eksis; sertifikat dibuat setelah verifikasi sisa baris = 0.
- Exporter: discovery dinamis, tabel rahasia (`tenant_encryption_keys`, dll) dikecualikan, kolom `secret|token|password|key_salt` diredaksi.

## 4. Atomic Task Checklist
- [x] **Phase 1: Key & Config Hardening (G-01, G-02, G-12)**
  - [x] 1.1 `Core\Security\AppKey` + `InsecureAppKeyException`.
  - [x] 1.2 Ganti seluruh fallback `APP_KEY` tersebar dengan `AppKey::get()`.
  - [x] 1.3 `APP_DEBUG` default `false`; perintah `key:generate`; watchdog melaporkan kunci insecure.
- [x] **Phase 2: Data Leak & Transaction Safety (G-03, G-08, G-10)**
  - [x] 2.1 Gitignore `storage/backups/`, `storage/exports/`, `storage/privacy/`.
  - [x] 2.2 `Connection` manual transaction ikut `transactionLevel`.
  - [x] 2.3 `runBenchmark()` try/finally.
- [x] **Phase 3: Outbox Exactly-Once Claim (G-07)**
  - [x] 3.1 Lock acquisition check: skip if lock held elsewhere.
  - [x] 3.2 Optimistic row claim: `UPDATE outbox_events SET status='processing', attempts=attempts+1` before dispatch.
  - [x] 3.3 Status 'processing' handling in `getPendingCount()` & `getStats()`.
- [x] **Phase 4: Privacy Engine Correctness (G-04, G-06, G-09)**
  - [x] 4.1 Schema-aware `anonymizeTenant()`: update only existing columns, scrub `contact_addresses` & child records.
  - [x] 4.2 Dynamic discovery in `shredTenant()`: purge leaf tables without tenant_id, keep FK ON with deferred checks.
  - [x] 4.3 Post-destruction verification: verify remaining rows == 0 across all tables before issuing certificate.
  - [x] 4.4 Exporter hardening: redact sensitive columns, exclude `tenant_encryption_keys`, export child records.
- [x] **Phase 5: Regression Tests & Verification**
  - [x] 5.1 Test regresi per celah di `tests/Unit/CoreSecurityRemediationTest.php` (9 tests, 43 assertions).
  - [x] 5.2 Full PHPUnit (512 tests, 2974 assertions) + typecheck hijau; update README & SESSION_HANDOFF; commit & merge.
