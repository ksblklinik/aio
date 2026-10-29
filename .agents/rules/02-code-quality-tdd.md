# Rule 02: Code Quality & Test-Driven Discipline

## Prinsip Dasar
- **Atomic Modifications**: Ubah hanya bagian kode yang dibutuhkan. Jangan memformat ulang file besar secara menyeluruh tanpa instruksi eksplisit.
- **TDD (Test-Driven Development)**:
  1. Tentukan kasus uji (*test cases*) untuk perilaku normal (*happy path*) dan skenario gagal (*error cases*).
  2. Implementasikan kode bisnis hingga seluruh kasus uji berhasil dilewati.
  3. Lakukan refactor dengan mempertahankan kepatuhan terhadap tes yang sudah dibuat.
- **Error Handling**: Tangani error secara eksplisit. Hindari `catch (e) {}` kosong atau silent failures.
- **Type Safety**: Manfaatkan static typing secara ketat (PHP 8.3 strict types, TypeScript). Hindari penggunaan tipe longgar (`any`, `mixed` tanpa validasi).
- **Offline-First Testing & Strict Mocking**:
  - Unit test **dilarang melakukan network call nyata** ke API pihak ketiga (payment gateway, OAuth, email service, external webhook).
  - Wajib gunakan mock adapter, in-memory fixtures, atau spy/stub. Unit test harus dapat dieksekusi secara instan (< 5 detik) dan 100% offline tanpa ketergantungan koneksi internet.

---

## Ketentuan Keamanan & Integritas Data

1. **Anti-Schema Drift Testing (Mandatory Real Migrations)**:
   - Pengujian yang membutuhkan database **dilarang menulis DDL tabel manual secara parsial**.
   - Wajib mengeksekusi berkas migrasi riil melalui `\Core\Database\Migrator::runMigrationsFrom(__DIR__ . '/../../database/migrations')` pada database pengujian (in-memory atau temporary SQLite). Ini menjamin pengujian selalu selaras 100% dengan skema produksi dan langsung mendeteksi kolom hilang atau *schema drift*.

2. **Strict Multi-Tenant Scoping & Parameter Binding**:
   - Setiap query data non-publik (SELECT, UPDATE, DELETE) pada modul bisnis WAJIB menyertakan klausul `WHERE tenant_id = ?`.
   - Wajib gunakan prepared statement parameter binding (`?` placeholders). **Dilarang keras melakukan konkatenasi string SQL mentah**.
   - Pengujian modul bisnis wajib memverifikasi isolasi multi-tenant (data Tenant A tidak boleh dapat diakses oleh Tenant B).

3. **Mitigasi Polusi State Antar-Test (Zero State Leakage)**:
   - Setiap test case yang menyetel `TenantContext` atau `AuthContext` wajib memanggil pembersihan di dalam `tearDown()`:
     ```php
     protected function tearDown(): void
     {
         TenantContext::getInstance()->clear();
         parent::tearDown();
     }
     ```
   - Hindari manipulasi properti statis yang bertahan melintasi siklus hidup pengujian.

4. **Mitigasi Presisi Aritmatika & Moneter (Invarian 19)**:
   - Perhitungan finansial, buku tarif, diskon, dan stok fisik **dilarang menggunakan bilangan pecahan mengambang (*binary floating-point / float*)**.
   - Gunakan bilangan bulat satuan terkecil (*integer base sub-units / cents*) atau fungsi desimal presisi tetap (`round()`, `bcmul()` jika diperlukan) untuk mencegah selisih pembulatan akuntansi.

5. **Preserve Context**: Jangan pernah menghapus komentar dokumentasi, lisensi, atau kode utilitas yang belum usang tanpa alasan yang jelas.
