---
name: code-review
description: Melakukan audit mandiri terhadap perubahan kode, kepatuhan arsitektur terhadap dokumen spec, dan mendeteksi regresi atau code smell.
---

# Skill: Code Review & Quality Audit

Gunakan skill ini setelah implementasi fitur selesai atau sebelum commit perubahan.

## Checklist Audit

1. **Kepatuhan Terhadap Spec:**
   - Apakah semua persyaratan fungsional pada dokumen spec sudah terpenuhi?
   - Apakah ada modifikasi di luar lingkup (*out of scope / non-goals*)?

2. **Kualitas Kode & Standar:**
   - Apakah ada sisa `console.log` debugging, kode mati (*dead code*), atau komentar TODO yang terlupakan?
   - Apakah error handling sudah menangani skenario batas (*edge cases*) yang tercatat di spec?
   - Apakah penamaan variabel, fungsi, dan tipe konsisten dengan konvensi proyek?

3. **Integritas Pengujian:**
   - Apakah pengujian mencakup skenario gagal (*sad path*) dan bukan hanya skenario berhasil (*happy path*)?
   - Apakah semua test lolos secara konsisten tanpa kondisi *flaky*?

4. **Laporan Audit:**
   - Buat ringkasan temuan:
     - **Pass**: Aspek yang sudah solid.
     - **Warning**: Hal yang berpotensi menimbulkan masalah atau perlu optimasi ringan.
     - **Blocker**: Masalah kritis yang harus diperbaiki sebelum dinyatakan selesai.
