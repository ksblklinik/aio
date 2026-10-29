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

2. **Eksekusi Atomik (TDD) - Satu Sub-Tugas per Iterasi:**
   - **Single Sub-Task Gate**: Fokus HANYA pada satu sub-tugas aktif. Dilarang mengerjakan beberapa sub-tugas sekaligus dalam satu giliran.
   - Buat atau perbarui file pengujian (unit/integrasi) terlebih dahulu jika relevan.
   - Implementasikan perubahan kode minimal untuk memenuhi kriteria tugas tersebut.

3. **Verifikasi Mandiri:**
   - Jalankan script verifikasi proyek:
     ```powershell
     powershell -ExecutionPolicy Bypass -File ./scripts/verify.ps1
     ```
   - Pastikan seluruh test berstatus **PASS** dan `npm run typecheck` bebas dari error kompilasi.

4. **Update Checkbox & Atomic Commit:**
   - Ubah `- [ ]` menjadi `- [x]` pada tugas tersebut di file spec.
   - Buat git commit atomik sesuai format Conventional Commits:
     ```bash
     git add path/to/changed/files specs/<nomor-spec>.md
     git commit -m "<type>(<scope>): <pesan> [spec-<nomor>/task-<id>]"
     ```
   - Jika semua sub-tugas dalam spec sudah selesai, perbarui status spec di header menjadi `Completed`.

5. **Laporkan Progres:**
   - Berikan laporan ringkas tugas apa yang baru saja diselesaikan, file yang diubah, dan status verifikasinya.
