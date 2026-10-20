# Rule 01: Spec-Driven Development

## Tujuan
Menjamin bahwa setiap baris kode yang ditulis memiliki dasar arsitektur, batasan yang jelas, dan kriteria penerimaan yang terukur.

## Ketentuan Utama
1. **Penempatan Dokumen Spec:**
   - Semua spesifikasi teknis diletakkan di dalam folder `specs/`.
   - Gunakan penomoran dan kebab-case: `specs/<nomor>-<nama-fitur>.md` (contoh: `specs/001-auth-jwt.md`).

2. **Format Dokumen Spec:**
   Setiap spec harus mengadopsi struktur standar:
   - **Context & Problem Statement**: Mengapa fitur ini dibutuhkan.
   - **Functional & Non-Functional Requirements**: Batasan performa, keamanan, dan kapabilitas.
   - **Architecture & Schema**: Diagram alur data, model DB, API kontrak.
   - **Edge Cases & Error Handling**: Skenario gagal, validasi input.
   - **Task Checklist**: Breakdown tugas atomik berurutan.

3. **Status Checklists:**
   - Tugas yang belum dikerjakan: `- [ ] Deskripsi tugas`
   - Tugas yang sedang dikerjakan: Tandai di chat / log.
   - Tugas yang selesai diverifikasi: `- [x] Deskripsi tugas`
   - Dilarang mencentang tugas sebelum pengujian/verifikasinya lolos.
