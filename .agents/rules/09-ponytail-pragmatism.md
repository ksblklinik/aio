# Rule 09: Ponytail Pragmatism & Anti-Bloat Minimalism

## Motto
> *"The best code is the code you never wrote."* — Dietrich Gebert (Project Ponytail)

## Tujuan
Mencegah penyakit kronis AI coding assistant: *over-engineering*, penambahan abstraksi/wrapper prematur, ketergantungan berlebih pada library eksternal, dan penulisan kode yang membengkak (*bloatware*). 
Aturan ini menuntut agen berpikir seperti *senior developer paling pragmatis*: **sangat tekun dan mendalam saat investigasi akar masalah, namun sangat enggan dan hemat saat menambahkan kode baru.**

---

## 7-Step Decision Ladder (Tangga Keputusan Ponytail)

Sebelum menulis baris kode baru, kelas baru, atau fungsi baru, agen **WAJIB** mengevaluasi 7 tangga berikut secara berurutan:

```
[1. Apakah perlu ada? (YAGNI)]
        │ (Jika YA)
[2. Apakah sudah ada di codebase?] ──> Gunakan ulang / refactor sedikit
        │ (Jika TIDAK)
[3. Bisakah ditangani fungsi native PHP 8.3 / SQLite?] ──> Gunakan native
        │ (Jika TIDAK)
[4. Bisakah ditangani fitur browser/HTML/CSS standar?] ──> Hindari JS/lib tambahan
        │ (Jika TIDAK)
[5. Apakah dependency yang sudah terinstall menyelesaikannya?] ──> Manfaatkan library yang ada
        │ (Jika TIDAK)
[6. Bisakah ditulis dalam 1-3 baris sederhana?] ──> Tulis inline yang jelas
        │ (Jika TIDAK)
[7. Solusi Paling Minimal (Surgical Implementation)] ──> Tulis kode seringkas mungkin
```

1. **Strict YAGNI (You Aren't Gonna Need It)**:
   - Tolak fitur spekulatif untuk "masa depan yang mungkin tidak pernah datang".
   - Dilarang membuat wrapper/layer abstraksi (misal: repository layer khusus, factory rumit, adapter perantara) jika belum ada minimal 3 kebutuhan berbeda yang nyata.
2. **Reuse Before Create**:
   - Selalu cari helper (`helpers.php`), service yang sudah ada, atau method SQL yang serupa sebelum membuat file atau fungsi baru.
3. **Native PHP 8.3 & SQLite WAL First**:
   - Manfaatkan fitur native modern PHP: `match`, typed properties, `array_*`, *nullsafe operator* `?->`, string functions.
   - Manfaatkan kapabilitas SQL native SQLite: conditional `UPDATE ... WHERE current_stock >= ?`, `ON CONFLICT`, `RETURNING`, `round()`. Hindari kalkulasi manual di memori PHP yang rawan *race condition*.
4. **Native Browser & Semantic CSS**:
   - Gunakan elemen semantic HTML5 dan Semantic Design Tokens (`var(--token)`). Hindari menarik library icon/animasi/komponen eksternal baru jika cukup dengan CSS murni.
5. **No New Dependencies Without Justification**:
   - Jangan menambahkan package baru di `composer.json` atau `package.json` tanpa izin eksplisit pengguna dan alasan teknis yang tidak terbantahkan.
6. **Simplicity Over Cleverness**:
   - Pilih kode yang lugas, mudah dibaca, dan minim baris daripada kode "pintar" yang berlapis-lapis dan sulit di-debug.
7. **Surgical Diffs**:
   - Ubah baris seminimal mungkin. Jangan memformat ulang seluruh file yang tidak relevan dengan tugas aktif.

---

## Batasan Yang Tidak Boleh Ditawar (Non-Negotiable Safeguards)

Pragmatisme dan prinsip "hemat menulis kode" **DILARANG KERAS** mengorbankan:
1. **Keamanan & Isolasi Multi-Tenant**: Tenant guard (`tenant_id`), token auth, password hashing, dan enkripsi data medis (PHI/PDP).
2. **Validasi Batas Kepercayaan (Trust Boundaries)**: Input sanitization, validasi form, CSRF token, fail-fast error handling.
3. **Integritas Konkurensi & Transaksi**: Atomic lock, DB transactions, dan offline-first data consistency.
4. **Kelengkapan Pengujian (TDD)**: Test suite harus tetap komprehensif, mencakup skenario normal dan gagal (*sad path*), serta verifikasi 100% lulus. Baris kode test tidak boleh dipangkas atas nama Ponytail.
5. **Deep Module Boundary**: Kontrak batas publik modul (`Contracts/*RegistryInterface.php`) adalah fondasi decoupling modular wajib sesuai [`codebase-design`](file:///c:/aplikasi/aio/.agents/skills/codebase-design/SKILL.md) dan BUKAN merupakan abstraksi prematur.
