# Rule 03: Self-Verification & Automated Checks

## Standar Selesai (Definition of Done)
Sebuah tugas atau sub-tugas baru dianggap **selesai** apabila telah memenuhi langkah verifikasi berikut:

1. **Syntax & Type Checking**:
   - Menjalankan type-check/compiler (misal: `tsc --noEmit`, `go vet`, `mypy`, dsb.).
   - Bebas dari error kompilasi dan linting kritis.

2. **Automated Tests**:
   - Menjalankan unit test suite atau test runner yang relevan.
   - Semua test baru dan test lama yang berkaitan wajib berstatus **PASS**.

3. **Runtime / Build Verification**:
   - Menjalankan build command (`npm run build`, `cargo build`, dsb.) untuk memastikan bundling/packaging sukses.
   - **Anti-False Positive Asset Verification (Anti Layar Kosong)**:
     - Dilarang menyimpulkan halaman web sukses hanya dari respons HTTP 200 pada root HTML.
     - Agen **wajib** memverifikasi bahwa file JavaScript (`.js`) dan CSS (`.css`) yang dirujuk oleh tag `<script>` dan `<link>` di dalam HTML dapat disajikan secara fisik oleh web server dengan status **HTTP 200** (tidak 404).
     - Pastikan web server development (misal PHP built-in server) memiliki guard bypass file statis yang aktif.

4. **Unified Verification Gate**:
   - Sebelum melakukan commit, jalankan script verifikasi proyek:
     ```powershell
     powershell -ExecutionPolicy Bypass -File ./scripts/verify.ps1
     ```
   - Pastikan script menghasilkan output `ALL CHECKS PASSED`.

5. **Status Update**:
   - Centang checkbox pada dokumen spec di folder `specs/`.
   - Ringkas hasil verifikasi dan berikan diff singkat kepada pengguna.
