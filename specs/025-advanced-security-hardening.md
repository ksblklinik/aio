# SPEC-025: Advanced Platform Security Hardening

- **Status**: `In Progress`
- **Owner / Author**: Antigravity Platform Team
- **Tanggal Dibuat**: 2026-10-27
- **Target File / Modul**: `core/Middleware/SecurityHeaders.php`, `core/Security/Encrypter.php`, `core/Security/Totp.php`, `core/Middleware/SanitizeInput.php`, `core/helpers.php`

---

## 1. Problem Statement & Context
Meskipun platform telah memiliki 6 lapis keamanan dasar (Isolasi multi-tenant, autentikasi Argon2id, RBAC Gate, proteksi SQLi/CSRF/XSS, Audit Logging, dan pre-commit scan), aplikasi enterprise yang memproses transaksi keuangan, data pribadi (PII), dan terhubung ke internet membutuhkan penguatan tingkat tinggi:
1. **Ketiadaan HTTP Security Headers**: Halaman berisiko mengalami *Clickjacking* (dimuat di dalam iframe situs phishing), *MIME-Sniffing confusion*, dan kebocoran referrer.
2. **Data Plaintext at Rest**: Data sensitif (nomor telepon, nomor rekening bank, konfigurasi token/secret API tenant) tersimpan sebagai teks biasa di database tanpa enkripsi terotentikasi.
3. **Ketiadaan Autentikasi Dua Faktor (2FA / TOTP)**: Akun penting (Superadmin, Owner, Manajer Keuangan) rentan jika password mereka bocor di internet atau terkena serangan phishing.
4. **Sanitasi Input Otomatis**: String payload yang mengandung karakter berbahaya (*null-bytes* `\0` atau path traversal tersembunyi) belum disterilkan secara otomatis di level pipeline HTTP.

---

## 2. Goals & Non-Goals

### Goals
- **HTTP Security Headers Middleware (`Core\Middleware\SecurityHeaders.php`)**:
  - Menyuntikkan header standar industri OWASP: `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`, dan `Content-Security-Policy`.
- **Authenticated Data Encryption Engine (`Core\Security\Encrypter.php`)**:
  - Enkripsi terotentikasi simetris **AES-256-GCM** berbasis `APP_KEY` dengan IV acak 12-byte dan Authentication Tag 16-byte untuk menjamin kerahasiaan (*confidentiality*) dan keaslian (*integrity*).
  - Global helper: `encrypt(mixed $value)` dan `decrypt(string $payload)`.
- **Native Two-Factor Authentication / TOTP Engine (`Core\Security\Totp.php`)**:
  - Implementasi murni PHP RFC 6238 Time-based One-Time Password (TOTP) kompatibel dengan Google Authenticator, Microsoft Authenticator, dan Authy.
  - Pembangkitan secret Base32, validasi kode 6-digit dengan toleransi drift waktu ($\pm 1$ step), pembuatan URI `otpauth://`, dan integrasi QR Code SVG native (`qr_code()`).
  - Global helper: `totp(): Totp`.
- **Input Sanitizer Middleware (`Core\Middleware\SanitizeInput.php`)**:
  - Otomatis mensterilkan *null-bytes* (`\0`), memangkas whitespace tak terlihat (*trim*), dan menetralkan upaya path traversal pada request input.

### Non-Goals
- Tidak memerlukan modul PHP tambahan di luar `openssl` dan `hash` (keduanya adalah ekstensi wajib PHP 8.2+).

---

## 3. Technical Architecture & Design

### Alur Request Masuk (Security Pipeline)
```
[Client Request] ──> [SanitizeInput] (Trim & Strip Null-Bytes)
                         │
                         ▼
                     [VerifyCsrfToken / ThrottleRequests]
                         │
                         ▼
                     [Route / Controller Action]
                         │
                         ▼
[Client Response] <── [SecurityHeaders] (X-Frame-Options, CSP, nosniff, etc.)
```

### Format Payload Enkripsi AES-256-GCM
```json
{
  "iv": "base64_encoded_12_bytes",
  "tag": "base64_encoded_16_bytes",
  "value": "base64_encoded_ciphertext"
}
```

---

## 4. Test Strategy
- **Unit Testing**:
  - `CoreAdvancedSecurityTest`:
    - Menguji injeksi Security Headers pada HTTP Response.
    - Menguji enkripsi dan dekripsi `Encrypter` dengan tipe data string, array, dan integer.
    - Menguji deteksi kegagalan dekripsi saat cipher dimanipulasi (*tampering check*).
    - Menguji pembangkitan secret TOTP Base32, validasi kode 6-digit, validasi toleransi waktu, dan pembangkitan URI `otpauth://`.
    - Menguji pembersihan null-bytes dan pemangkasan whitespace oleh `SanitizeInput`.
- **Integration Verification**:
  - Eksekusi `./scripts/verify.ps1` untuk memvalidasi test suite global (target 310+ tests, 100% pass) dan `tsc --noEmit`.

---

## 5. Atomic Task Checklist

- [ ] **Phase 1: HTTP Security Headers Middleware (`core/Middleware/SecurityHeaders.php`)**
  - [ ] 1.1 Buat class `Core\Middleware\SecurityHeaders` yang menyuntikkan header keamanan OWASP ke Response.
- [ ] **Phase 2: AES-256-GCM Authenticated Encryption Engine (`core/Security/Encrypter.php`)**
  - [ ] 2.1 Buat class `Core\Security\Encrypter` dengan cipher `aes-256-gcm`.
  - [ ] 2.2 Daftarkan singleton di `Application.php` dan tambahkan helper `encrypt()` & `decrypt()` di `core/helpers.php`.
- [ ] **Phase 3: Native Two-Factor Authentication Engine (`core/Security/Totp.php`)**
  - [ ] 3.1 Buat class `Core\Security\Totp` dengan RFC 6238 TOTP algorithm dan Base32 encoder/decoder.
  - [ ] 3.2 Tambahkan helper `totp()` di `core/helpers.php`.
- [ ] **Phase 4: Input Sanitizer Middleware (`core/Middleware/SanitizeInput.php`)**
  - [ ] 4.1 Buat class `Core\Middleware\SanitizeInput` yang mensterilkan null-bytes dan membersihkan string input.
- [ ] **Phase 5: Unit Testing & Self-Verification**
  - [ ] 5.1 Buat unit test komprehensif di `tests/Unit/CoreAdvancedSecurityTest.php`.
  - [ ] 5.2 Jalankan `./scripts/verify.ps1`, pastikan seluruh test lolos 100%.
  - [ ] 5.3 Lakukan atomic commit dan merge branch fitur ke `main`.
  - [ ] 5.4 Perbarui dokumentasi spec dan `SESSION_HANDOFF.md`.
