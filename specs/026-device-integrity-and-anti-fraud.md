# SPEC-026: Core Device Integrity & Anti-Fraud Toolkit

- **Status**: `Completed`
- **Owner / Author**: Antigravity Platform Team
- **Tanggal Dibuat**: 2026-10-27
- **Target File / Modul**: `core/Security/IntegrityGuard.php`, `core/Audit/AuditLogger.php`, `core/helpers.php`

---

## 1. Problem Statement & Context
Aplikasi operasional, multi-tenant SaaS, absensi lapangan, dan sistem finansial enterprise menghadapi potensi kecurangan data (*fraud*) dari level pengguna atau manipulasi database:
1. **Location Spoofing (Fake GPS)**: Pengguna lapangan memasang aplikasi Fake GPS di smartphone untuk mengelabui absensi kehadiran atau kunjungan sales.
2. **Device Sharing / Multi-Account Abuse**: Satu perangkat fisik digunakan bergantian untuk mengabsenkan rekan kerja yang tidak hadir atau mengklaim voucher secara masif.
3. **Clock Tampering**: Pengguna memundurkan/memajukan jam sistem perangkat lokal untuk memalsukan waktu transaksi.
4. **Disposable Identity (Burner Email)**: Pendaftaran tenant atau akun gratis menggunakan alamat email sekali pakai (*temp-mail*) untuk eksploitasi kuota API atau spam.
5. **Database Manipulation (Internal Tampering)**: Manipulasi riwayat aktivitas langsung di database (misal via phpMyAdmin/SQLite client) oleh oknum teknisi internal tanpa meninggalkan jejak standar.

---

## 2. Goals & Non-Goals

### Goals
- **Core Geolocation & Location Anti-Spoofing Primitive (`Core\Security\IntegrityGuard`)**:
  - Validasi metadata telemetri GPS (`is_mock`, `accuracy`, batas geografis).
  - Kalkulasi *Impossible Travel* dan kecepatan perpindahan (Haversine formula vs $\Delta t$).
- **Device Fingerprinting & Binding (`Core\Security\IntegrityGuard`)**:
  - Pembangkitan hash unik perangkat fisik berbasis kombinasi atribut hardware/browser.
  - Verifikasi binding perangkat dengan toleransi *timing-attack safe* (`hash_equals`).
- **Clock Tampering & Time Drift Detection (`Core\Security\IntegrityGuard`)**:
  - Deteksi deviasi waktu antara timestamp client dan trusted atomic server time.
- **Disposable Email Shield (`Core\Security\IntegrityGuard`)**:
  - Filter domain email sementara/burner terhadap daftar penyedia email sekali pakai populer.
- **Cryptographic Tamper-Evident Hash-Chaining pada Audit Log (`Core\Audit\AuditLogger`)**:
  - Penambahan rantai hash kriptografis (`prev_hash` & `current_hash`) pada setiap baris `audit_logs`.
  - Kemampuan verifikasi mandiri integritas audit log (`verifyChain()`) untuk mendeteksi perubahan/penghapusan data di level database.
- **Global Helper**:
  - `integrity(): IntegrityGuard` dan `is_disposable_email(string $email): bool`.

### Non-Goals
- Tidak memerlukan package eksternal Composer tambahan (murni pure PHP 8.3 & native hashing).
- Tidak memaksakan aturan bisnis spesifik (misal: threshold nominal kasir) ke dalam Core.

---

## 3. Technical Architecture & Design

### 1. Impossible Travel Formula
$$\text{Distance} = 2 R \cdot \arcsin\left(\sqrt{\sin^2\left(\frac{\Delta \text{lat}}{2}\right) + \cos(\text{lat}_1)\cos(\text{lat}_2)\sin^2\left(\frac{\Delta \text{lon}}{2}\right)}\right)$$
$$\text{Speed} = \frac{\text{Distance}}{\Delta t} \quad (\text{km/jam})$$
Jika $\text{Speed} > 1000\text{ km/jam}$ (atau threshold yang ditentukan), ditandai sebagai `impossible_travel`.

### 2. Hash-Chained Audit Trail
```
[Genesis Hash: "0000...0000"]
      │
      ▼
[Log #1] ──> current_hash = HMAC-SHA256(prev_hash + log_payload, SECRET)
      │
      ▼
[Log #2] ──> current_hash = HMAC-SHA256(Log#1_hash + log_payload, SECRET)
      │
      ▼
[Log #3] ──> current_hash = HMAC-SHA256(Log#2_hash + log_payload, SECRET)
```
Jika baris Log #2 diubah/dihapus manual via DBMS, rantai verifikasi Log #3 akan putus (*Broken Chain Detected*).

---

## 4. Test Strategy
- **Unit Testing**:
  - `tests/Unit/CoreDeviceIntegrityTest.php`:
    - Menguji kalkulasi jarak dan deteksi *Impossible Travel* (misal Jakarta - Tokyo dalam 10 menit).
    - Menguji deteksi flag `is_mock` dan akurasi tidak wajar.
    - Menguji deteksi time drift jam client.
    - Menguji pembentukan dan verifikasi device fingerprint hash.
    - Menguji filter disposable email.
    - Menguji pembentukan hash chain pada audit log dan verifikasi deteksi manipulasi saat data dirusak.

---

## 5. Atomic Task Checklist

- [x] **Phase 1: Database Migration untuk Audit Log Hash-Chaining**
  - [x] 1.1 Buat migrasi penambahan kolom `prev_hash` dan `current_hash` pada tabel `audit_logs`.
- [x] **Phase 2: Core Device Integrity & Anti-Fraud Engine (`core/Security/IntegrityGuard.php`)**
  - [x] 2.1 Buat class `Core\Security\IntegrityGuard` dengan fungsi Location Anti-Spoofing, Impossible Travel, Device Fingerprint, Time Drift, dan Disposable Email Filter.
  - [x] 2.2 Daftarkan singleton di `Application.php` dan tambahkan helper di `core/helpers.php`.
- [x] **Phase 3: Hash-Chained Audit Logging (`core/Audit/AuditLogger.php`)**
  - [x] 3.1 Integrasikan perhitungan rantai hash HMAC-SHA256 pada saat pencatatan audit log baru.
  - [x] 3.2 Buat method `verifyChain(?int $tenantId = null): array` untuk audit integritas database.
- [x] **Phase 4: Unit Testing & Self-Verification**
  - [x] 4.1 Buat unit test komprehensif di `tests/Unit/CoreDeviceIntegrityTest.php`.
  - [x] 4.2 Jalankan `./scripts/verify.ps1`, pastikan seluruh test lolos 100%.
  - [x] 4.3 Lakukan atomic commit dan merge branch fitur ke `main`.
  - [x] 4.4 Perbarui dokumentasi spec dan `SESSION_HANDOFF.md`.
