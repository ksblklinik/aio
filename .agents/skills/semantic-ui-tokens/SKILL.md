---
name: semantic-ui-tokens
description: Mencegah regresi styling tema, inkonsistensi kontras (teks tidak terbaca), dan bug mode gelap/terang parsial dengan menerapkan disiplin Semantic Design Tokens terpusat (CSS Variables).
---

# Skill: Semantic UI & Design Token System

Skill ini wajib dipatuhi oleh seluruh AI Agent dan pengembang saat mendesain atau memodifikasi antarmuka visual (UI/CSS/Tailwind) di repositori **aio**.

> **Pembagian Peran UI**:
> * Gunakan **`semantic-ui-tokens`** (skill ini) untuk tata kelola token warna terpusat, CSS variables tema gelap/terang, dan kebijakan *atomic rewrite* kontras.
> * Gunakan skill pendamping [**`anti-slop-design`**](file:///c:/aplikasi/aio/.agents/skills/anti-slop-design/SKILL.md) untuk estetika layout, variasi asimetris, eliminasi klise AI, dan *tactile micro-interactions* (`active:scale-[0.98]`, `font-mono tabular-nums`).

---

## 🛑 Akar Masalah yang Dilarang Keras (Anti-Pattern)
- **DILARANG** melakukan modifikasi warna parsial atau tambal-sulam baris per baris (*patching class-by-class*) di komponen UI yang sedang aktif.
- **DILARANG** menulis kelas utilitas `dark:` secara sporadis di masing-masing tag HTML (misal: `bg-white dark:bg-slate-900 text-gray-900 dark:text-white`). Pola ini terbukti meninggalkan elemen tertinggal (*zombie elements*) yang mengakibatkan teks hitam di latar gelap atau sebaliknya.
- **DILARANG** menggunakan warna dasar yang kontrasnya tidak dijamin (misal teks putih di latar putih).

---

## 🛡️ Protokol Wajib (Golden Rules)

### 1. Gunakan Semantic Tokens Terpusat
Semua komponen wajib menggunakan kelas warna semantik yang dipetakan ke CSS Variables di `resources/css/app.css` dan `tailwind.config.js`:

| Token Semantik | Kelas Tailwind | Penggunaan |
|---|---|---|
| `--bg-canvas` | `bg-canvas` | Latar belakang halaman paling luar |
| `--bg-surface` | `bg-surface` | Kartu (*Card*), modal, sidebar, header |
| `--bg-surface-subtle` | `bg-surface-subtle` | Input background, hover rows, card footer |
| `--border-subtle` | `border-border-subtle` | Garis pemisah halus, border kartu |
| `--border-main` | `border-border-main` | Garis aktif, border input saat fokus |
| `--text-main` | `text-text-main` | Teks judul utama, isi tabel, nilai metrik |
| `--text-muted` | `text-text-muted` | Teks sub-judul, tanggal, keterangan kecil |
| `--brand-primary` | `bg-brand-primary`, `text-brand-primary` | Tombol utama, tab aktif, link aksi |
| `--brand-primary-light` | `bg-brand-primary-light` | Latar badge, container icon utama |

### 2. Strategi Atomic Rewrite vs Partial Edits
Jika antarmuka visual mengalami masalah kontras atau layout perlu diganti:
- **Lakukan Full Rewrite**: Tulis ulang file komponen/layout secara utuh (`write_to_file` dengan `Overwrite: true`) dengan palet token yang konsisten.
- **Jangan Menambal Kelas**: Jangan mencoba mengganti satu atau dua kata di dalam baris JSX yang kompleks karena rentan gagal pencocokan teks dan merusak hierarki tampilan.

### 3. Checklist Verifikasi Mandiri (Contrast & Build Audit)
Sebelum menganggap pekerjaan UI selesai:
1. Jalankan `npm run build` (`tsc && vite build`) untuk memastikan tidak ada kesalahan kompilasi.
2. Periksa tampilan dalam **KEDUA MODE**: Klik `ThemeToggle` untuk menguji mode terang dan mode gelap.
3. Pastikan tidak ada teks yang sulit dibaca (kontras rasio minimal WCAG AA 4.5:1).
