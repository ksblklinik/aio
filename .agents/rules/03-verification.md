# Rule 03: Self-Verification & Automated Checks

## Standar Selesai (Definition of Done)
Sebuah tugas atau sub-tugas baru dianggap **selesai** apabila telah memenuhi langkah verifikasi berikut:

1. **Syntax & Type Checking**:
   - Menjalankan type-check/compiler (`npm run typecheck` / `tsc --noEmit`).
   - Bebas dari error kompilasi dan linting kritis.

2. **Automated Tests (100% Passing)**:
   - Menjalankan unit & integration test suite (`php vendor/bin/phpunit`).
   - Semua test baru dan seluruh test lama wajib berstatus **PASS** (Zero Regressions).

3. **Runtime & Build Verification (Anti Layar Kosong)**:
   - Menjalankan build frontend (`npm run build`) untuk memastikan packaging bersih.
   - **Anti-False Positive Asset Verification**:
     - Dilarang menyimpulkan halaman web sukses hanya dari respons HTTP 200 pada root HTML.
     - Agen **wajib** memverifikasi bahwa file bundle JavaScript (`.js`) dan CSS (`.css`) yang dirujuk oleh tag `<script>` dan `<link>` di dalam HTML dapat disajikan secara fisik oleh web server dengan status **HTTP 200** (tidak 404).

4. **Pembersihan Artefak Debugging (Anti-Zombie Debugging)**:
   - Sebelum commit, periksa diff (`git diff`) dan pastikan tidak ada sisa debugging liar:
     - PHP: `var_dump(`, `dd(`, `print_r(`, `error_log(`.
     - JS/TS: `console.log(`, `debugger;`.
   - File scratch sementara di root wajib dibersihkan.

5. **Unified Verification Gate**:
   - Sebelum melakukan commit, jalankan script verifikasi proyek:
     ```powershell
     powershell -ExecutionPolicy Bypass -File ./scripts/verify.ps1
     ```
   - *Alternatif Lintas Platform (Linux/CI/Bash)*:
     ```bash
     php vendor/bin/phpunit && npm run typecheck && npm run build
     ```
   - Pastikan seluruh pengujian menghasilkan output sukses tanpa error.

6. **Status Update**:
   - Centang checkbox tugas pada dokumen spec terkait di folder `specs/`.
   - Dokumentasikan ringkasan verifikasi sebelum melakukan commit atomik.
