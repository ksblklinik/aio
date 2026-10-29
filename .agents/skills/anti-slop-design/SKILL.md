---
name: anti-slop-design
description: Standar Principal Design Technologist untuk mengeliminasi klise frontend AI (slop), menghadirkan micro-interactions taktil, variasi layout asimetris, dan estetika premium pada antarmuka web.
---

# Skill: Anti-Slop Design, Micro-Interactions & Frontend Taste

Gunakan skill ini saat merancang, membangun, atau merefaktor antarmuka visual (Inertia React / Tailwind / CSS) di repositori **aio**. 
Skill ini menuntut antarmuka terlihat seperti **karya studio desain produk kelas atas (*hand-crafted*)**, bukan hasil template generik bawaan AI (*AI monoculture*).

---

## 1. Daftar Hitam Klise AI (The Slop Blacklist)

Dilarang keras menghasilkan kode antarmuka yang memuat pola-pola klise berikut:

1. **The Purple/Indigo Gradient Pill**:
   - Dilarang menggunakan gradien ungu-biru generik (`from-purple-600 to-indigo-600`, `from-violet-500 to-fuchsia-500`) pada badge atau tombol utama.
   - Gunakan warna brand semantik terpusat (`bg-brand-primary`, `text-brand-primary`).
2. **Infinite 3-Card Grid Monoculture**:
   - Dilarang selalu membuat layout berulang: *Hero teks tengah $\rightarrow$ 3 kartu fitur seragam dengan icon kecil $\rightarrow$ tombol CTA raksasa*.
   - Terapkan variasi struktur: layout asimetris, split panel, newspaper/editorial layout, atau data density table yang fungsional.
3. **Raw Emojis Sebagai Ikon Antarmuka**:
   - Dilarang menggunakan emoji teks (⚡, 🚀, 💡, 🩺, 💊, 📊) sebagai ikon UI resmi.
   - Wajib gunakan vector SVG konsisten (Lucide Icons / Heroicons) dengan bobot garis (*stroke width*) seragam.
4. **Metrik Halusinasi Tanpa Data Riil**:
   - Dilarang memunculkan statistik palsu (*"50,000+ pengguna puas"*, *"+47% efisiensi"*).
   - Tampilkan metrik riil dari database (kuota, saldo kas, antrean aktif, stok batch) atau kosongkan jika belum ada data.
5. **Transisi Lambat & Serampangan**:
   - Dilarang menempelkan `transition-all duration-300` secara buta di semua elemen.
   - Transisi interaktif wajib cepat, ringan, dan terarah (100–150ms `ease-out`).
6. **Container & Border Clutter**:
   - Dilarang membungkus setiap elemen mikro ke dalam kotak ber-border tebal dengan drop-shadow berat sehingga tampilan terasa sesak.
   - Manfaatkan ruang negatif (*whitespace*), tipografi hierarkis, dan pemisah halus (`border-border-subtle`).

---

## 2. Micro-Interactions & State Matrix Taktil

Setiap elemen interaktif (tombol, baris tabel, kartu, tab, input form) wajib memiliki **state matrix penuh**:

1. **Feedback Tekan Fisik (Tactile Active State)**:
   - Tombol dan kartu klik-able wajib memiliki efek fisik saat ditekan: `active:scale-[0.98] transition-transform duration-100`.
2. **Aksesibilitas Fokus Tajam (`focus-visible`)**:
   - Dilarang mengandalkan outline biru default browser yang kusam.
   - Gunakan ring fokus yang kontras dan bersih: `focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary focus-visible:ring-offset-2`.
3. **Hover Elevation Terukur**:
   - Baris tabel data: Berikan highlight halus saat kursor melintas (`hover:bg-surface-subtle transition-colors duration-150`).
   - Kartu tindakan: Sedikit elevasi halus tanpa shadow berlebihan.
4. **Contextual Skeleton & Loading States**:
   - Dilarang menampilkan layar putih kosong atau spinner lambat di tengah layar.
   - Gunakan animasi denyut (*shimmer/pulse*) skeleton yang proporsional sesuai bentuk data tabel atau kartu yang sedang dimuat.
5. **Ergonomi Data Angka & Monospace**:
   - Seluruh kolom angka moneter, kuantitas stok, persentase tarif, dan jam transaksi wajib menggunakan font angka proporsional: `font-mono tabular-nums`.

---

## 3. Disiplin Integrasi Semantic Design Tokens

Skill ini wajib terikat 100% dengan [Rule 07 (07-semantic-ui-tokens.md)](file:///c:/aplikasi/aio/.agents/rules/07-semantic-ui-tokens.md):

* **Canvas**: `bg-canvas` untuk latar belakang terluar.
* **Surface**: `bg-surface` untuk panel utama, sidebar, kartu data.
* **Subtle**: `bg-surface-subtle` untuk baris selang-seling, input form, chip status.
* **Borders**: `border-border-subtle` untuk garis pembatas halus.
* **Typography**: `text-text-main` untuk judul/isi data utama, `text-text-muted` untuk label keterangan/tanggal.
* **Dual-Mode Assurance**: Tampilan wajib diuji kontras keterbacaannya pada mode terang dan mode gelap sebelum dinyatakan selesai.

---

## 4. 7-Axis Quality Gate (Evaluasi Mandiri Sebelum Menyajikan UI)

Sebelum menyelesaikan pekerjaan antarmuka, agen wajib mengaudit hasil karyanya terhadap 7 sumbu penilaian (skor 1–5):

| Sumbu | Pertanyaan Audit |
|---|---|
| **1. Philosophy** | Apakah mood UI sesuai dengan domain bisnis (bersih, presisi, kredibel, enterprise)? |
| **2. Hierarchy** | Apakah pandangan mata langsung memahami urutan 1 (aksi utama), 2 (data kunci), 3 (keterangan)? |
| **3. Execution** | Apakah token semantik, contrast ratio WCAG AA, dan dark mode berjalan sempurna? |
| **4. Specificity** | Apakah data dan label spesifik untuk domain proyek (bukan teks lorem ipsum atau metrik generik)? |
| **5. Restraint** | Apakah elemen visual yang tidak esensial sudah dipangkas (tidak ada dekorasi berlebih)? |
| **6. Variety** | Apakah layout memecah template AI yang monoton (ada ritme dinamis & densitas profesional)? |
| **7. Motion** | Apakah animasi mikro terasa taktil, cepat (<150ms), dan tidak memperlambat produktivitas user? |

*Jika ada sumbu yang bernilai di bawah 4, lakukan refactor visual sebelum menyerahkan kode.*
