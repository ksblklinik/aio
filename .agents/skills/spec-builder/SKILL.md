---
name: spec-builder
description: Menghasilkan spesifikasi teknis terstruktur (Spec) dan pemecahan tugas atomik sebelum menulis kode aplikasi. Gunakan saat pengguna ingin merancang fitur baru atau melakukan perubahan arsitektural.
---

# Skill: Spec Builder

Gunakan skill ini ketika pengguna meminta pembuatan fitur baru, refactor besar, atau modul baru yang membutuhkan perencanaan sistematis.

## Alur Kerja

1. **Eksplorasi & Clarification (Grill):**
   - Periksa apakah kebutuhan pengguna sudah cukup detail (problem statement, boundaries, data inputs/outputs).
   - Jika belum jelas, tanyakan pertanyaan kunci terkait arsitektur, edge cases, atau teknologi yang diinginkan.

2. **Evaluasi Kebutuhan Server MCP (Proactive MCP Check):**
   - Evaluasi apakah spec merancang kapabilitas Core (adapter database enterprise Postgres/MySQL/Redis, queue distributed) atau integrasi eksternal (browser E2E, SatuSehat, Payment Gateway).
   - Jika ada, rekomendasikan konfigurasi MCP yang sesuai ke pengguna (sesuai [06-mcp-lifecycle-recommendation.md](file:///c:/aplikasi/aio/.agents/rules/06-mcp-lifecycle-recommendation.md)) untuk didaftarkan ke `.agents/mcp_config.json`.

3. **Cek Nomor Urut Spec:**
   - Baca file [specs/README.md](file:///c:/aplikasi/aio/specs/README.md) untuk melihat daftar spec yang sudah ada.
   - Tentukan nomor urut berikutnya (misal `001`, `002`, dst.).

4. **Buat File Spesifikasi Baru:**
   - Salin dan modifikasi struktur dari [specs/templates/SPEC_TEMPLATE.md](file:///c:/aplikasi/aio/specs/templates/SPEC_TEMPLATE.md).
   - Tulis spesifikasi lengkap di `specs/<nomor>-<nama-fitur>.md`.
   - Terapkan prinsip **Ponytail Pragmatism** (Rule 09): pastikan tidak ada abstraksi prematur atau over-engineering di dalam rancangan.
   - Pastikan bagian **Atomic Task Checklist** memiliki rincian langkah kerja yang jelas, granular, dan teratur.

5. **Perbarui Indeks:**
   - Tambahkan baris baru pada tabel indeks di [specs/README.md](file:///c:/aplikasi/aio/specs/README.md).

6. **Konfirmasi ke Pengguna:**
   - Tampilkan ringkasan spec kepada pengguna dan mintalah konfirmasi apakah ada bagian yang perlu disesuaikan sebelum fase implementasi dimulai.
