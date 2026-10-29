---
name: systematic-debugging
description: Protokol investigasi bug dan analisis akar masalah (RCA) secara sistematis tanpa tebak-tebak berhadiah (anti-flailing). Gunakan saat pengujian gagal, runtime crash, atau perilaku sistem tidak terduga.
---

# Skill: Systematic Debugging & RCA

Gunakan skill ini ketika menghadapi error, bug yang sulit dilacak, atau saat rangkaian pengujian gagal berulang kali. Hindari mengubah baris kode secara acak (*flailing*).

## Protokol 4 Tahap (4-Step RCA Protocol)

```
[1. Reproduce] ──> [2. Isolate & Inspect] ──> [3. Formulate Hypothesis] ──> [4. Minimal Fix & Verify]
```

### 1. Reproduce (Reproduksi Konsisten)
- Jangan ubah kode aplikasi sebelum bug dapat direproduksi secara konsisten.
- Jalankan satu perintah spesifik atau buat unit/integration test minimal yang memicu kegagalan tersebut (*reproduction script/test*).
- Konfirmasi status gagal: **FAIL** terkonfirmasi.

### 2. Isolate & Inspect (Isolasi Akar Masalah)
- Amati pesan error, *stack trace*, dan baris file yang memicu kegagalan.
- Periksa status asumsi:
  - Apakah input data sesuai tipe dan struktur yang diharapkan?
  - Apakah ada *race condition*, state yang belum diinisialisasi, atau null/undefined dereference?
  - Apakah masalah disebabkan oleh dependensi eksternal, konfigurasi environment, atau skema DB?
- Jika diperlukan, pasang logging terarah di titik krusial (hapus kembali setelah selesai).

### 3. Formulate Hypothesis (Rumuskan Hipotesis)
- Nyatakan hipotesis secara eksplisit:
  > *"Error terjadi karena fungsi `X` menerima nilai `null` saat kondisi `Y`, sehingga gagal memanggil method `Z`."*
- Pastikan solusi yang direncanakan menyelesaikan **akar masalah** (*root cause*), bukan hanya meredam gejala (misal: bukan sekadar menyelimuti dengan `try/catch` kosong atau fallback liar).

### 4. Minimal Fix & Verify (Perbaikan Minimal & Verifikasi)
- Terapkan perbaikan terkecil yang menyelesaikan masalah secara presisi.
- Jalankan kembali skrip reproduksi/test: pastikan status berubah menjadi **PASS**.
- Jalankan seluruh test suite (`powershell -ExecutionPolicy Bypass -File ./scripts/verify.ps1` atau `php vendor/bin/phpunit` dan `npm run typecheck`) untuk memastikan **tidak ada regresi** (*no collateral damage*).
- Bersihkan semua log debug sementara sebelum commit.
