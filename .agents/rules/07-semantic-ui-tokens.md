# Rule 07: Semantic UI Tokens & Theme Integrity

## Tujuan
Mencegah bug regresi visual, cacat tema parsial (elemen tertinggal saat ganti dark/light mode), dan teks yang tidak terbaca akibat modifikasi tambal-sulam.

---

## Aturan Baku (Non-Negotiable)

1. **Semantic Tokens First**:
   - Dilarang keras menuliskan kombinasi `dark:bg-...` dan `dark:text-...` secara sporadis dan tersebar di setiap elemen HTML.
   - Semua warna latar, border, dan teks wajib merujuk ke token semantik terpusat (`bg-canvas`, `bg-surface`, `bg-surface-subtle`, `border-border-subtle`, `text-text-main`, `text-text-muted`, `bg-brand-primary`).

2. **Single Switch in CSS Variables**:
   - Perubahan tema hanya boleh terjadi di level `:root` dan `.dark` pada [resources/css/app.css](file:///c:/aplikasi/aio/resources/css/app.css).
   - Seluruh komponen HTML harus otomatis beradaptasi tanpa perlu modifikasi kelas manual.

3. **Anti-Patching Policy**:
   - Jika suatu komponen memiliki masalah kontras visual yang parah, lakukan *rewrite* utuh dan terstruktur pada file tersebut. Jangan melakukan modifikasi parsial sepotong-sepotong yang menyisakan *zombie styles*.

4. **Dual-Theme Verification**:
   - Sebelum tugas UI ditandai selesai (`[x]`), agen wajib memastikan kode valid saat di-build (`npm run build`) dan kontras teks dapat dibaca dengan jelas pada mode terang maupun mode gelap.
