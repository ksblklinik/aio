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
   - Apakah ada sisa debugging liar, kode mati (*dead code*), atau komentar TODO yang terlupakan?
   - Apakah error handling sudah menangani skenario batas (*edge cases*) yang tercatat di spec?
   - Apakah penamaan variabel, fungsi, dan tipe konsisten dengan konvensi proyek?

3. **Audit Arsitektur & Netralitas (Skill `codebase-design`):**
   - **Deep Modules vs Shallow Wrappers**: Apakah antarmuka publik ringkas dan menyembunyikan kompleksitas orkestrasi internal di balik service, tanpa rantai wrapper tipis 1:1 yang tidak berguna?
   - **Platform & Economic Neutrality**: Apakah entitas dan skema netral terhadap model komersial vs non-profit (mendukung transaksi berbayar maupun subsidi penuh/hibah Rp 0)?
   - **Unidirectional Topology**: Apakah alur dependensi strictly searah (Level 3 $\rightarrow$ Level 2 $\rightarrow$ Level 1) dan bebas dari ketergantungan melingkar (*zero circular coupling*)?
   - **The Deletion Test**: Jika modul ini dihapus kelak, apakah abstraksinya terisolasi bersih tanpa membocorkan logika ke modul pemanggil?

4. **Audit Ponytail Pragmatism (Rule 09):**
   - **Line Count Justification**: Bisakah solusi ini ditulis dengan baris yang lebih hemat dan lugas?
   - **Anti-Premature Abstraction**: Apakah ada Interface, Factory, atau DTO berlapis yang dibuat prematur tanpa ada kebutuhan 3 use-case nyata?
   - **Native PHP 8.3 & SQL First**: Apakah ada loop/kalkulasi manual yang sebenarnya sudah disediakan oleh fungsi array PHP atau klausa SQL atomik?

5. **Integritas Pengujian & Multi-Tenant (Rule 02 & Rule 08):**
   - **Anti-Schema Drift**: Apakah test database mengeksekusi migrasi riil via `Migrator::runMigrationsFrom` dan bukan DDL parsial manual?
   - **Tenant Scoping Guard**: Apakah seluruh query modul bisnis mengunci `WHERE tenant_id = ?` dan test memverifikasi isolasi data Tenant A vs Tenant B?
   - **Zero Infrastructure Leakage**: Apakah modul bisnis bebas dari keterikatan driver database mentah (*engine-agnostic*)?
   - Apakah pengujian mencakup skenario gagal (*sad path*) dan lolos 100%?

6. **Laporan Audit:**
   - Buat ringkasan temuan:
     - **Pass**: Aspek yang sudah solid.
     - **Warning**: Hal yang berpotensi menimbulkan masalah atau perlu optimasi ringan.
     - **Blocker**: Masalah kritis yang harus diperbaiki sebelum dinyatakan selesai.
