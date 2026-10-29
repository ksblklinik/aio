# Rule 02: Code Quality & Test-Driven Discipline

## Prinsip Dasar
- **Atomic Modifications**: Ubah hanya bagian kode yang dibutuhkan. Jangan memformat ulang file besar secara menyeluruh tanpa instruksi eksplisit.
- **TDD (Test-Driven Development)**:
  1. Tentukan kasus uji (*test cases*) untuk perilaku normal (*happy path*) dan skenario gagal (*error cases*).
  2. Implementasikan kode bisnis hingga seluruh kasus uji berhasil dilewati.
  3. Lakukan refactor dengan mempertahankan kepatuhan terhadap tes yang sudah dibuat.
- **Error Handling**: Tangani error secara eksplisit. Hindari `catch (e) {}` kosong atau silent failures.
- **Type Safety**: Manfaatkan static typing secara ketat jika bahasa/runtime mendukungnya (misal TypeScript, Go, Rust, Python typing). Hindari penggunaan tipe yang melonggarkan keamanan tanpa alasan valid.
- **Offline-First Testing & Strict Mocking**:
  - Unit test **dilarang melakukan network call nyata** ke API pihak ketiga (payment gateway, OAuth, email service, external webhook).
  - Wajib gunakan mock adapter, in-memory fixtures, atau spy/stub. Unit test harus dapat dieksekusi secara instan (< 5 detik) dan 100% offline tanpa ketergantungan koneksi internet.
- **Anti-Schema Drift Testing (Mandatory Real Migrations)**:
  - Pengujian yang membutuhkan database **dilarang menulis DDL tabel manual secara parsial**.
  - Wajib mengeksekusi berkas migrasi riil melalui `\Core\Database\Migrator::runMigrationsFrom(__DIR__ . '/../../database/migrations')` pada database pengujian (in-memory atau temporary SQLite). Ini menjamin pengujian selalu selaras 100% dengan skema produksi dan langsung mendeteksi kolom hilang atau *schema drift*.
- **Strict Multi-Tenant Scoping**:
  - Setiap query data non-publik (SELECT, UPDATE, DELETE) pada modul bisnis WAJIB menyertakan klausul `WHERE tenant_id = ?`.
  - Pengujian modul bisnis wajib memverifikasi isolasi multi-tenant (data Tenant A tidak boleh dapat diakses oleh Tenant B).
  - Setiap test case yang menyetel `TenantContext` wajib memanggil `TenantContext::getInstance()->clear()` di dalam `tearDown()` untuk mencegah polusi state antar-test.
- **Preserve Context**: Jangan pernah menghapus komentar dokumentasi, lisensi, atau kode utilitas yang belum usang tanpa alasan yang jelas.
