# Rule 07: Semantic UI Tokens, Anti-Slop & Theme Integrity

## Tujuan
Mencegah bug regresi visual, cacat tema parsial (elemen tertinggal saat ganti dark/light mode), teks yang tidak terbaca akibat modifikasi tambal-sulam, serta mengeliminasi klise antarmuka generik bawaan AI (*AI design slop*).

---

## Aturan Baku (Non-Negotiable)

1. **Semantic Tokens First**:
   - Dilarang keras menuliskan kombinasi `dark:bg-...` dan `dark:text-...` secara sporadis dan tersebar di setiap elemen HTML.
   - Semua warna latar, border, dan teks wajib merujuk ke token semantik terpusat (`bg-canvas`, `bg-surface`, `bg-surface-subtle`, `border-border-subtle`, `text-text-main`, `text-text-muted`, `bg-brand-primary`).

2. **Single Switch in CSS Variables**:
   - Perubahan tema hanya boleh terjadi di level `:root` dan `.dark` pada [resources/css/app.css](file:///c:/aplikasi/aio/resources/css/app.css).
   - Seluruh komponen HTML harus otomatis beradaptasi tanpa perlu modifikasi kelas manual.

3. **Anti-Slop Design & Variety (Pencegahan Klise AI)**:
   - Dilarang menghasilkan klise AI: gradien ungu-biru (`from-purple-600 to-indigo-600`), 3 kartu fitur seragam yang monoton, penggunaan raw emoji (⚡, 🚀, 💡) sebagai ikon UI, dan metrik palsu.
   - Gunakan vector SVG konsisten (Lucide/Heroicons), layout asimetris fungsional, dan kepadatan data yang ergonomis (*dense yet scannable*).
   - Rujuk skill [anti-slop-design](file:///c:/aplikasi/aio/.agents/skills/anti-slop-design/SKILL.md) untuk pedoman 7-Axis Quality Gate.

4. **Tactile Micro-Interactions & State Matrix**:
   - Setiap elemen klik-able wajib memiliki respon fisik aktif: `active:scale-[0.98] transition-transform duration-100`.
   - Ring fokus tajam untuk aksesibilitas: `focus-visible:ring-2 focus-visible:ring-brand-primary`.
   - Data numerik/finansial/kuantitas wajib menggunakan font angka proporsional: `font-mono tabular-nums`.

5. **Anti-Patching Policy**:
   - Jika suatu komponen memiliki masalah kontras visual yang parah, lakukan *rewrite* utuh dan terstruktur pada file tersebut. Jangan melakukan modifikasi parsial sepotong-sepotong yang menyisakan *zombie styles*.

6. **Dual-Theme & Build Verification**:
   - Sebelum tugas UI ditandai selesai (`[x]`), agen wajib memastikan kode lolos typecheck (`npm run typecheck`), build sukses (`npm run build`), dan kontras teks terbaca tajam pada mode terang maupun mode gelap (WCAG AA 4.5:1).
