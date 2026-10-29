# SPEC-047: Diagnostics Persistence, PII Redaction & Test Context Isolation

- **Status**: `Completed`
- **Owner / Author**: Antigravity & Lead Engineer
- **Tanggal Dibuat**: 2026-10-29
- **Target File / Modul**: `core/Diagnostics/*`, `core/Foundation/Application.php`, `core/Console/Commands/SentinelSlowQueriesCommand.php`, `tests/TestCase.php`

---

## 1. Problem Statement & Context

Pada audit keamanan dan resiliensi Core (`core_gap_audit.md`), teridentifikasi dua celah tersisa pasca SPEC-044, SPEC-045, dan SPEC-046:

1. **G-11 (Diagnostics & Profiler Volatility / PII Exposure)**:
   - **Lazy Attachment**: `$db->setProfiler($profiler)` hanya dipanggil saat `PerformanceSentinelInterface` di-*resolve* dari Container. Query basis data yang terjadi pada siklus bootstrap atau request web biasa yang tidak memanggil `sentinel()` luput dari pemantauan.
   - **Cross-Process Volatility**: `QueryProfiler` hanya menyimpan rekaman query lambat dalam memori proses (`$slowQueries = []`). Ketika operator menjalankan perintah CLI `php aio sentinel:slow-queries`, proses CLI baru dimulai dengan memori bersih sehingga selalu menampilkan 0 query lambat.
   - **PII Parameter Leakage**: Parameter query (`$params`) disimpan mentah (*raw*). Jika query melibatkan kolom sensitif (kata sandi, NIK, nomor telepon, token auth, nomor kartu), data pribadi pasien/pengguna dapat bocor melalui log atau perintah diagnosa.

2. **G-14 (Test State Leakage & Lack of Central Isolation Base)**:
   - Unit test di `tests/Unit/` mewarisi `PHPUnit\Framework\TestCase` secara langsung tanpa *tear-down lifecycle* terpusat.
   - Modifikasi singleton pada `Container`, konteks aktif pada `TenantContext`, `Config`, atau `Logger` berpotensi mencemari eksekusi test berikutnya (*state pollution* / *cross-test contamination*).

---

## 2. Goals & Non-Goals

### Goals
- [x] **Eager / Boot-time Profiler Attachment**: Menghubungkan `QueryProfiler` ke `Connection` sejak fase registrasi/boot aplikasi jika diaktifkan via konfigurasi (`diagnostics.profiler_enabled`).
- [x] **Persistent Slow Query Ring-Buffer**: Menyimpan rekaman query lambat ke berkas terisolasi berpagar kapasitas (`storage/diagnostics/slow_queries.json`) menggunakan *file locking* (`flock`) dan batas rotasi (*bounded FIFO*, misal maks 100 entri).
- [x] **PII & Sensitive Parameter Redaction**: Menyaring parameter query secara rekursif sebelum dicatat ke memori/disk dengan menyamarkan kata sandi, token, NIK, email, kunci otentikasi, dan nomor kartu menjadi `[REDACTED]`.
- [x] **Central Base `Tests\TestCase`**: Menyediakan kelas dasar test `Tests\TestCase` yang membersihkan seluruh *static state* (`Container::setInstance(null)`, `TenantContext::setTenantId(null)`, dll.) pada setiap `tearDown()`.
- [x] **100% Test Pass**: Memastikan seluruh test suite lama (521 tests) dan test suite baru lolos 100%.

### Non-Goals
- Tidak menggantikan sistem logging terpusat `core/Log/Logger.php` (hanya untuk ring-buffer diagnosa performa).
- Tidak mengalihkan penyimpanan diagnosa ke database relasional utama (mencegah deadlock / kompetisi lock SQLite saat terjadi lonjakan beban).

---

## 3. Technical Architecture & Design

### Alur Kerja Profiler & PII Redactor
```
[Database Query Executed] 
         │
         ▼
[QueryProfiler::record($sql, $params, $durationMs)]
         │
         ├──> [PiiRedactor::redactParams($params, $sql)] ──> Sanitized Params
         │
         ├──> [In-Memory Ring Buffer]
         │
         └──> [Persistent Ring Buffer Writer (storage/diagnostics/slow_queries.json)]
```

### Sanitasi PII (Parameter Redactor)
Pemeriksaan sensitivitas berbasis:
1. **Nama Kunci Parameter (Key matching)**: `password`, `secret`, `token`, `key`, `nik`, `pin`, `cvv`, `auth`, `credential`.
2. **Pola Nilai (Pattern matching)**:
   - Format Token / Hash: Bcrypt `$2y$...`, Argon2 `$argon2...`, JWT `eyJ...`.
   - NIK Indonesia: 16 digit numerik berurutan.
   - Nomor Telepon & Email.

### Persistent Ring-Buffer Storage
Berkas: `storage/diagnostics/slow_queries.json`.
Format JSON array:
```json
[
  {
    "sql": "SELECT * FROM users WHERE email = ?",
    "params": ["[REDACTED:EMAIL]"],
    "duration_ms": 32.45,
    "recorded_at": "2026-10-29T13:45:00+07:00"
  }
]
```
Dengan mekanisme `flock(LOCK_EX)` dan pemotongan FIFO otomatis saat melebihi batas entri.

---

## 4. Edge Cases & Error Scenarios

- **Berkas Storage Belum Ada / Tidak Writable**: Buat direktori `storage/diagnostics` otomatis. Jika gagal izin akses, *graceful fallback* ke in-memory saja tanpa menghentikan request utama.
- **Lock Timeout pada Berkas Ring-Buffer**: Gunakan `LOCK_NB` (non-blocking) agar thread aplikasi web tidak terhambat (*zero latency overhead*).
- **Nilai Non-Scalar pada Params**: Tangani array bersarang (*nested array*), objek, resource, atau `null` secara aman tanpa memicu fatal error / crash.
- **Test Isolation Reset**: Pastikan `tearDown()` pada `Tests\TestCase` membungkus pembersihan dalam blok `try/finally` sehingga kegagalan asersi di dalam test tidak menghambat reset state.

---

## 5. Test Strategy

1. **Unit Test `QueryProfilerTest` & `PerformanceSentinelTest`**:
   - Uji penapisan PII parameter sensitif (kata sandi, token, NIK).
   - Uji persistensi ring-buffer berkas dan pembatasan FIFO.
   - Uji pembacaan query lambat lintas proses melalui `sentinel:slow-queries`.
2. **Unit Test `TestCaseIsolationTest`**:
   - Uji bahwa kontaminasi state (singleton container, tenant ID) terisolasi dan bersih antar-test.
3. **Global Regression Verification**:
   - Jalankan seluruh test suite PHPUnit.

---

## 6. Atomic Task Checklist
 
- [x] **Phase 1: PII Redaction & Profiler Persistence**
  - [x] 1.1 Implementasi `QueryProfiler::redactParams()` dan penapisan data sensitif.
  - [x] 1.2 Implementasi penyimpanan persisten ring-buffer (`storage/diagnostics/slow_queries.json`) pada `QueryProfiler`.
  - [x] 1.3 Perbarui `Application.php` untuk mengaitkan profiler ke `Connection` saat inisialisasi boot.
  - [x] 1.4 Perbarui `SentinelSlowQueriesCommand` untuk membaca rekaman persisten jika tersedia.
- [x] **Phase 2: Base `Tests\TestCase` Context Isolation**
  - [x] 2.1 Buat kelas `tests/TestCase.php` dengan implementasi `tearDown()` yang mereset `Container`, `TenantContext`, `Config`, dan `Logger`.
  - [x] 2.2 Uji isolasi dengan test suite pembuktian anti-pollution.
- [x] **Phase 3: Unit Tests & Verification**
  - [x] 3.1 Buat unit test komprehensif untuk PII Redaction & Persistent Slow Queries.
  - [x] 3.2 Eksekusi full test suite (528 tests) hingga 100% PASS.
  - [x] 3.3 Verifikasi TypeScript type-check dan hygiene Git commit.
