# SPEC-015B: Platform Hardening, Auth Lifecycle UI & Infrastructure Resilience

- **Status**: `Approved`
- **Owner / Author**: Antigravity & Architecture Team
- **Tanggal Dibuat**: 2026-10-27
- **Target File / Modul**:
  - `core/Mail/AccountLifecycleService.php`
  - `app/Modules/Auth/Controllers/ForgotPasswordController.php`
  - `app/Modules/Auth/Controllers/ResetPasswordController.php`
  - `app/Modules/Workspaces/Controllers/InvitationController.php`
  - `resources/js/Pages/Auth/ForgotPassword.tsx`
  - `resources/js/Pages/Auth/ResetPassword.tsx`
  - `resources/js/Pages/Workspaces/AcceptInvitation.tsx`
  - `core/Middleware/ThrottleRequests.php`
  - `core/Router.php`
  - `core/Database/TenantScope.php`
  - `core/helpers.php`
  - `resources/css/app.css`
  - `tests/Unit/AuthLifecycleUiTest.php`
  - `tests/Unit/RateLimiterTest.php`
  - `tests/Unit/SoftDeletesTenantScopeTest.php`

---

## 1. Problem Statement & Context
Pada SPEC-015 (*Universal Platform Services*), fondasi layanan platform seperti Storage, Settings, Sequence, Schedule, Mailer, dan Pagination telah sukses diintegrasikan. Selain itu, backend `AccountLifecycleService` sudah mampu menangani pengiriman email token untuk reset password dan undangan workspace.

Namun, sebelum aio dibungkus menjadi template SaaS universal yang dapat dirilis ke publik (SPEC-016), terdapat 4 celah infrastruktur dan user journey yang belum tuntas:
1. **Frontend Auth Lifecycle Belum Ada**: Belum ada controller HTTP dan tampilan React untuk alur Forgot Password, Reset Password, dan Accept Invitation. Pengguna yang lupa password atau menerima email undangan tidak memiliki antarmuka untuk menyelesaikan aksinya.
2. **Ketiadaan Rate Limiting Middleware**: Belum ada mekanisme rate limiter (`throttle:maxAttempts,decayMinutes`) untuk memitigasi brute-force attack pada endpoint sensitif (login, reset password, webhook, dan REST API).
3. **Ketiadaan Dukungan Soft Deletes pada TenantScope**: Di aplikasi bisnis (klinik, POS, invoice, inventori), penghapusan data master/transaksi tidak boleh langsung hard delete demi integritas audit dan kepatuhan hukum. Diperlukan fungsionalitas `softDelete()`, `restore()`, serta filter otomatis `withoutTrashed()` / `withTrashed()` pada `TenantScope`.
4. **Audit Helper Global & Konsistensi Semantic Tokens**: Belum tersedianya helper global `audit(...)` pada `core/helpers.php`, serta perlunya standardisasi penggunaan Semantic Design Tokens (CSS Variables) pada halaman autentikasi baru agar serasi dengan mode tema (light/dark).

---

## 2. Goals & Non-Goals

### Goals
- [x] Menyediakan UI dan alur lengkap untuk **Forgot Password**, **Reset Password**, dan **Accept Workspace Invitation** menggunakan Inertia React.
- [x] Mengimplementasikan middleware **`ThrottleRequests`** dengan format routing `throttle:maxAttempts,decayMinutes` (zero external dependency, berbasis storage/cache lokal).
- [x] Menambahkan dukungan **Soft Deletes** (`deleted_at`) pada **`TenantScope`** dengan metode `softDelete()`, `restore()`, serta seleksi `withoutTrashed()`, `withTrashed()`, dan `onlyTrashed()`.
- [x] Mendaftarkan helper global **`audit()`** pada `core/helpers.php` untuk mempermudah audit logging di seluruh modul.
- [x] Memastikan 100% kepatuhan terhadap Semantic Design Tokens di halaman auth baru tanpa hardcoded colors liar.
- [x] Mempertahankan 100% test pass rate dan zero regression pada seluruh test suite eksisting.

### Non-Goals
- Tidak mengubah skema tabel `tenants` atau `users` secara destruktif.
- Tidak menambahkan dependensi eksternal berat (seperti Redis) untuk rate limiter; rate limiter harus mandiri menggunakan SQLite / local filesystem cache.

---

## 3. Technical Architecture & Design

### 3.1 Auth Lifecycle UI & Endpoints
Alur autentikasi lifecycle diatur melalui routing web berikut:
- `GET /forgot-password` $\rightarrow$ Form input email lupa password (`Auth/ForgotPassword.tsx`).
- `POST /forgot-password` $\rightarrow$ Validasi email, kirim link reset via `AccountLifecycleService::sendPasswordResetLink`, redirect kembali dengan flash notification.
- `GET /reset-password` $\rightarrow$ Form reset password dengan token URL parameter (`Auth/ResetPassword.tsx`).
- `POST /reset-password` $\rightarrow$ Validasi token & konfirmasi password baru via `AccountLifecycleService::resetPassword`.
- `GET /invitations/accept` $\rightarrow$ Tampilan konfirmasi undangan workspace (`Workspaces/AcceptInvitation.tsx`).
- `POST /invitations/accept` $\rightarrow$ Penerimaan undangan dan penautan user ke tenant melalui `AccountLifecycleService::acceptInvitation`.

```
[User Browser]
   │
   ├─► GET /forgot-password ─────► ForgotPasswordController ──► Inertia::render('Auth/ForgotPassword')
   ├─► POST /forgot-password ────► AccountLifecycleService  ──► Mailer::queue() + Flash Msg
   ├─► GET /reset-password?token= ► ResetPasswordController  ──► Inertia::render('Auth/ResetPassword')
   ├─► POST /reset-password ─────► AccountLifecycleService  ──► users.password_hash Updated
   ├─► GET /invitations/accept?t= ► InvitationController     ──► Inertia::render('Workspaces/AcceptInvitation')
   └─► POST /invitations/accept ──► AccountLifecycleService  ──► tenant_user Joined
```

### 3.2 Rate Limiting Middleware (`ThrottleRequests`)
Middleware throttling murni PHP tanpa dependensi Redis:
```php
namespace Core\Middleware;

class ThrottleRequests
{
    public function handle(Request $request, callable $next, int $maxAttempts = 60, int $decayMinutes = 1): Response;
}
```
- **Syntax Routing**: `throttle:5,1` (maksimal 5 request per 1 menit).
- **Key Generation**: `sha1($request->ip() . '|' . ($request->user() ? $request->user()->id : 'guest') . '|' . $request->path())`.
- **Response Header**:
  - `X-RateLimit-Limit`: Jumlah maksimum percobaan yang diperbolehkan.
  - `X-RateLimit-Remaining`: Sisa kuota request dalam jendela waktu aktif.
  - `Retry-After`: Jumlah detik hingga kuota di-reset.
- **Handling 429**: Mengembalikan respons JSON 429 (untuk API / Inertia requests) atau halaman error dengan status code 429.

### 3.3 Soft Deletes di `TenantScope`
Mekanisme soft deletes ditambahkan pada `Core\Database\TenantScope`:
- `softDelete(string $table, string $additionalWhere = '', array $params = []): int`: Mengisi kolom `deleted_at = CURRENT_TIMESTAMP`.
- `restore(string $table, string $additionalWhere = '', array $params = []): int`: Mereset kolom `deleted_at = NULL`.
- `forceDelete(string $table, string $additionalWhere = '', array $params = []): int`: Menghapus baris fisik secara permanen (hard delete).
- Fluent scope builder atau toggle:
  - `withTrashed()`: Membolehkan data yang berstatus soft-deleted ikut terpanggil.
  - `onlyTrashed()`: Hanya memanggil data yang memiliki `deleted_at IS NOT NULL`.
  - `withoutTrashed()`: Perilaku default yang menyaring `deleted_at IS NULL` secara transparan.

### 3.4 Helper Global `audit()`
```php
function audit(?string $action = null, ?string $description = null, array $properties = []): \Core\Audit\AuditLogger|int
```
Jika dipanggil tanpa parameter `audit()`, mengembalikan instance `Core\Audit\AuditLogger`. Jika dipanggil dengan `$action` dan `$description`, langsung mencatat entri audit log dan mengembalikan ID audit log yang tercipta.

---

## 4. Edge Cases & Error Scenarios
1. **Invalid / Expired Reset Token**:
   - Jika token reset password salah atau sudah kadaluarsa, form `ResetPassword.tsx` menampilkan pesan error jelas dan opsi tombol untuk meminta link baru.
2. **Brute Force Rate Limiter Exceeded**:
   - Saat limit tercapai, return status 429 `Too Many Requests` beserta header `Retry-After: 60`.
3. **Workspace Invitation Already Accepted / Expired**:
   - Menampilkan status expired / invalid yang elegan di UI `AcceptInvitation.tsx` dengan arahan menghubungi admin pengundang.
4. **Soft Delete pada Tabel Tanpa Kolom `deleted_at`**:
   - Melempar exception yang jelas atau fallback aman tanpa memutus eksekusi aplikasi secara misterius.

---

## 5. Test Strategy
- **Unit Test Auth Lifecycle**: Pengujian endpoint Forgot Password, Reset Password, dan Invitation Acceptance dengan mock/in-memory SQLite.
- **Unit Test Rate Limiter**: Simulasi loop request hingga melebihi limit, verifikasi header HTTP (`X-RateLimit-Limit`, `X-RateLimit-Remaining`), dan respons 429.
- **Unit Test Soft Deletes**: Pengujian operasi `softDelete()`, `restore()`, `forceDelete()`, serta filter `withoutTrashed()`, `withTrashed()`, dan `onlyTrashed()`.
- **Integritas Build & Types**: `npm run build` dan `npx tsc --noEmit` untuk memastikan tidak ada error kompilasi TypeScript/React.

---

## 6. Atomic Task Checklist

- [x] **Phase 1: Backend Rate Limiter Middleware (`Core\Middleware\ThrottleRequests`)**
  - [x] 1.1 Buat unit test `tests/Unit/RateLimiterTest.php`.
  - [x] 1.2 Implementasikan kelas `Core\Middleware\ThrottleRequests` dengan driver cache/in-memory/file storage.
  - [x] 1.3 Integrasikan parsing prefix `throttle:max,decay` ke dalam `Core\Router.php`.
  - [x] 1.4 Verifikasi test `RateLimiterTest.php` lulus 100%.

- [x] **Phase 2: Database Soft Deletes di `Core\Database\TenantScope`**
  - [x] 2.1 Buat unit test `tests/Unit/SoftDeletesTenantScopeTest.php`.
  - [x] 2.2 Implementasikan `softDelete()`, `restore()`, `forceDelete()`, `withoutTrashed()`, `withTrashed()`, dan `onlyTrashed()` di `TenantScope`.
  - [x] 2.3 Verifikasi kompatibilitas terhadap query `selectAll`, `selectOne`, `count`, dan `paginate`.
  - [x] 2.4 Verifikasi test `SoftDeletesTenantScopeTest.php` lulus 100%.

- [ ] **Phase 3: Global Audit Helper & Core Polish**
  - [ ] 3.1 Daftarkan helper function `audit()` di `core/helpers.php`.
  - [ ] 3.2 Buat unit test verifikasi helper `audit()` di test suite.
  - [ ] 3.3 Verifikasi fungsi logging audit berjalan baik dengan atau tanpa context aktif.

- [ ] **Phase 4: Auth Lifecycle Backend Controllers & Routes**
  - [ ] 4.1 Buat `App\Modules\Auth\Controllers\ForgotPasswordController.php` (show + send).
  - [ ] 4.2 Buat `App\Modules\Auth\Controllers\ResetPasswordController.php` (show + reset).
  - [ ] 4.3 Buat `App\Modules\Workspaces\Controllers\InvitationController.php` (show + accept).
  - [ ] 4.4 Daftarkan routes lifecycle di `routes/web.php` dengan proteksi middleware `throttle`.
  - [ ] 4.5 Buat unit test `tests/Unit/AuthLifecycleUiTest.php` untuk menguji alur controller & respons Inertia/JSON.

- [ ] **Phase 5: Auth Lifecycle React Pages & Semantic Tokens Alignment**
  - [ ] 5.1 Buat komponen `resources/js/Pages/Auth/ForgotPassword.tsx`.
  - [ ] 5.2 Buat komponen `resources/js/Pages/Auth/ResetPassword.tsx`.
  - [ ] 5.3 Buat komponen `resources/js/Pages/Workspaces/AcceptInvitation.tsx`.
  - [ ] 5.4 Perbarui `resources/js/Pages/Auth/Login.tsx` untuk menyertakan tautan "Lupa kata sandi?".
  - [ ] 5.5 Audit penerapan CSS Semantic Tokens pada seluruh form auth baru.
  - [ ] 5.6 Jalankan `npx tsc --noEmit` dan `npm run build` untuk memvalidasi bundle aset.

- [ ] **Phase 6: Full Verification, Clean-up & Handoff Update**
  - [ ] 6.1 Jalankan seluruh test suite PHPUnit (`vendor/bin/phpunit`).
  - [ ] 6.2 Jalankan verifikasi lengkap via `./scripts/verify.ps1`.
  - [ ] 6.3 Perbarui tabel indeks di `specs/README.md`.
  - [ ] 6.4 Perbarui catatan status di `.agents/SESSION_HANDOFF.md`.
