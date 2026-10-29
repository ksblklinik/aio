# Rule 01: Spec-Driven Development

## Tujuan
Menjamin bahwa setiap baris kode yang ditulis memiliki dasar arsitektur, batasan yang jelas, dan kriteria penerimaan yang terukur untuk mencegah *vibe coding* serampangan.

---

## Ketentuan Utama

1. **Penempatan Dokumen Spec:**
   - Semua spesifikasi teknis diletakkan di dalam folder `specs/`.
   - Gunakan penomoran dan kebab-case: `specs/<nomor>-<nama-fitur>.md` (contoh: `specs/001-auth-jwt.md`).
   - Nomor urut wajib mengacu pada tabel indeks di [specs/README.md](file:///c:/aplikasi/aio/specs/README.md).

2. **Format Standar Dokumen Spec:**
   Setiap spec wajib mengadopsi format dari [specs/templates/SPEC_TEMPLATE.md](file:///c:/aplikasi/aio/specs/templates/SPEC_TEMPLATE.md) yang memuat:
   - **Context & Problem Statement**: Latar belakang, motivasi bisnis/teknis, dan dampak jika tidak dibuat.
   - **Goals & Non-Goals**: Batasan eksplisit apa yang diselesaikan dan apa yang di luar lingkup.
   - **Architecture & Schema**: Diagram alur, skema tabel/migrasi, API endpoint / kontrak interface.
   - **Edge Cases & Error Scenarios**: Skenario batas (*sad paths*), kegagalan jaringan, payload tak valid.
   - **Test Strategy**: Strategi unit test, integrasi, dan verifikasi mandiri.
   - **Atomic Task Checklist**: Breakdown tugas bertahap berformat checkbox `[ ]`.

3. **Status Checklists Atomik:**
   - Tugas belum dikerjakan: `- [ ] Deskripsi tugas`
   - Tugas selesai & terverifikasi: `- [x] Deskripsi tugas`
   - **Dilarang mencentang tugas sebelum pengujian/verifikasinya lolos 100%**.

---

## Matriks Mitigasi Risiko Spec

1. **Mitigasi Deviasi Spek di Tengah Jalan (Mid-Flight Spec Revision Protocol)**:
   - Jika saat implementasi ditemukan kebutuhan perubahan arsitektur atau arah teknis:
     1. **STOP KODE**: Hentikan penulisan kode implementasi baru.
     2. **UPDATE SPEC**: Perbarui dokumen spec target di `specs/` terlebih dahulu, catat alasan penyesuaian di bagian Problem/Context, dan sesuaikan checklist tugas.
     3. **KONFIRMASI**: Mintalah persetujuan pengguna sebelum melanjutkan kode dengan arah baru.

2. **Mitigasi Scope Creep (Anti-Gold-Plating)**:
   - Agen dilarang menambahkan fitur spekulatif di luar daftar `Goals` spec aktif.
   - Jika ada ide perbaikan atau fitur masa depan yang menarik, catat pada sub-bab `Future Iterations` atau usulkan sebagai spec baru terpisah, jangan dicampur ke spec berjalan.
