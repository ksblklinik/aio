---
name: spec-implement
description: Mengeksekusi tugas-tugas dari dokumen spec secara atomik dan bertahap dengan disiplin TDD dan self-verification.
---

# Skill: Spec Implement

Gunakan skill ini untuk melanjutkan pengerjaan tugas dari dokumen spesifikasi yang sudah disetujui.

## Alur Kerja

1. **Identifikasi Dokumen & Tugas:**
   - Baca dokumen spec target di folder `specs/`.
   - Cari tugas pertama yang masih belum dicentang (`- [ ]`).

2. **Eksekusi Atomik (TDD):**
   - Fokus hanya pada satu sub-tugas tersebut.
   - Buat atau perbarui file pengujian (unit/integrasi) terlebih dahulu jika relevan.
   - Implementasikan perubahan kode minimal untuk memenuhi kriteria tugas tersebut.

3. **Verifikasi Mandiri:**
   - Jalankan test suite atau linter menggunakan tool terminal.
   - Pastikan tidak ada kegagalan (*test pass* dan *no compilation errors*).

4. **Update Checkbox:**
   - Ubah `- [ ]` menjadi `- [x]` pada tugas tersebut di file spec.
   - Jika semua sub-tugas dalam satu fase/spec sudah selesai, perbarui status spec menjadi `Completed`.

5. **Laporkan Progres:**
   - Berikan laporan ringkas tugas apa yang baru saja diselesaikan, file yang diubah, dan status verifikasinya.
