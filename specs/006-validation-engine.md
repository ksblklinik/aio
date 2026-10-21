# SPEC-006: Form Validation & Field-Level Error Bag Engine

- **Status**: `Completed`
- **Owner / Author**: aio Framework Architecture Team
- **Tanggal Dibuat**: 2026-10-21
- **Target File / Modul**: `core/Validation`, `core/Http`, `core/Inertia`, `resources/js`

---

## 1. Problem Statement & Context
Sebelumnya, validasi input formulir dilakukan secara manual dan ad-hoc di dalam controller (misal: `if ($name === '') { $this->session->flash('error', '...'); }`). 
Dampak negatif dari pendekatan ini:
1. **Pesan Kesalahan Tidak Spesifik**: Pesan error hanya muncul sebagai banner umum di atas halaman, bukan melekat pada field input terkait.
2. **Duplikasi Kode**: Setiap controller harus menulis pengecekan if-else berulang kali untuk required, email format, min length, dll.
3. **Inkonsistensi UX**: Pengguna kesulitan mengetahui input mana yang bermasalah saat mengisi form panjang.
4. **Potensi Kerentanan**: Tidak adanya sanitasi & whitelist validation yang ketat sebelum data diproses ke database.

---

## 2. Goals & Non-Goals

### Goals
- [x] Membangun `Core\Validation\Validator` mandiri dengan aturan deklaratif (`required`, `email`, `min`, `max`, `string`, `numeric`, `in`, `confirmed`, `unique`).
- [x] Membangun `Core\Validation\ValidationException` yang membawa error bag per-field.
- [x] Integrasi otomatis dengan Inertia.js props (`errors: { email: '...', password: '...' }`) sehingga hook React `useForm().errors` langsung berfungsi.
- [x] Menambahkan trait/helper `ValidatesRequests` pada Controller dasar agar sintaks validasi seringkas `$this->validate($request, $rules)`.
- [x] Menerapkan validasi field-level pada halaman Auth (`Login.tsx`, `Register.tsx`) dan form scaffold modul (`Modules/Index.tsx`).
- [x] Unit test komprehensif (100% test coverage untuk seluruh aturan validasi).

### Non-Goals
- Tidak mengganti pustaka form React sisi klien (tetap menggunakan `@inertiajs/react` `useForm`).
- Tidak membuat form builder dinamis (fokus pada data contracts dan error feedback).

---

## 3. Technical Architecture & Design

### Alur Kerja Validasi
```
[User Form Submit]
       │
       ▼
[Controller: $this->validate($request, $rules)]
       │
       ├─── (Lolos) ──> Lanjut ke Service & Simpan Database
       │
       └─── (Gagal) ──> Lempar ValidationException
                              │
                              ├── Flash $errors ke Session
                              └── Redirect Back ke Halaman Form
                                     │
                                     ▼
                      [Inertia Shared Props: 'errors']
                                     │
                                     ▼
                      [React: useForm().errors.field]
```

### Aturan Validasi Inti (Supported Rules)
| Rule | Format | Deskripsi |
| :--- | :--- | :--- |
| `required` | `required` | Field tidak boleh null, string kosong, atau array kosong. |
| `email` | `email` | Harus format alamat surel yang valid (`FILTER_VALIDATE_EMAIL`). |
| `min` | `min:8` | Panjang string/array minimal N, atau nilai angka minimal N. |
| `max` | `max:255` | Panjang string/array maksimal N, atau nilai angka maksimal N. |
| `string` | `string` | Harus bertipe string. |
| `numeric` | `numeric` | Harus bernilai angka numerik. |
| `in` | `in:owner,admin,member` | Nilai harus ada dalam daftar opsi yang ditentukan. |
| `confirmed` | `confirmed` | Mengharuskan kecocokan dengan field `{field}_confirmation`. |
| `unique` | `unique:users,email` | Memeriksa keunikan data di tabel database. |

---

## 4. Atomic Task Checklist

- [x] **Phase 1: Validation Engine Core (`core/Validation`)**
  - [x] 1.1 Buat class `Core\Validation\Validator` dengan parsing rules string (`required|email|min:8`).
  - [x] 1.2 Implementasikan rule handlers: `required`, `email`, `min`, `max`, `string`, `numeric`, `in`, `confirmed`, `unique`.
  - [x] 1.3 Buat class `Core\Validation\ValidationException`.
  - [x] 1.4 Tulis unit test komprehensif di `tests/Unit/ValidatorTest.php`.

- [x] **Phase 2: Controller & Inertia Pipeline Integration**
  - [x] 2.1 Buat trait/helper `Core\Validation\ValidatesRequests` untuk pemanggilan instan di controller.
  - [x] 2.2 Hubungkan error bag dengan Session Flash dan bagikan ke `Inertia::share(['errors' => ...])` di `public/index.php`.
  - [x] 2.3 Refactor `LoginController` dan `RegisterController` menggunakan `Validator`.
  - [x] 2.4 Refactor `ModuleManagerController` menggunakan `Validator`.

- [x] **Phase 3: React Form UX & Field-Level Error Display**
  - [x] 3.1 Perbarui `Login.tsx` untuk menampilkan border merah dan pesan `errors.email` & `errors.password`.
  - [x] 3.2 Perbarui `Register.tsx` untuk menampilkan pesan `errors.name`, `errors.email`, `errors.password`, `errors.workspace_name`.
  - [x] 3.3 Perbarui form modal scaffold di `Modules/Index.tsx` dengan error feedback per field.

- [x] **Phase 4: Full Suite Verification & Git Discipline**
  - [x] 4.1 Jalankan PHPUnit test suite (82 tests passing 100%).
  - [x] 4.2 Jalankan `npm run build` (tsc & vite).
  - [x] 4.3 Verifikasi melalui `./scripts/verify.ps1`.
  - [x] 4.4 Perbarui `specs/README.md` dan `.agents/SESSION_HANDOFF.md`.
  - [x] 4.5 Atomic Git commit.
