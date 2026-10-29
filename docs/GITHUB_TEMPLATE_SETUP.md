# Panduan Aktivasi GitHub Template Repository

Dokumen ini memandu maintainer untuk mengonfigurasi repositori **aio** sebagai **Template Repository** di GitHub, sehingga pengguna lain dapat membuat repositori baru dengan satu klik tanpa menduplikasi riwayat commit lama.

---

## 1. Mengaktifkan Flag "Template Repository" di GitHub

1. Buka repositori `aio` di GitHub via peramban web.
2. Klik tab **Settings** di bagian atas repositori.
3. Pada halaman **General** (bagian paling atas, tepat di bawah nama repository):
   - Centang opsi: **[x] Template repository**.
4. Perubahan akan tersimpan secara otomatis.

> **Apa dampaknya?**
> Tombol hijau **"Use this template"** akan muncul di halaman utama repositori, memungkinkan pengembang membuat repositori baru yang bersih dalam hitungan detik.

---

## 2. Struktur Rilis & Tagging Git

Untuk merilis versi boilerplate yang stabil:
```bash
# Buat lightweight / annotated tag
git tag -a v1.0.0-core -m "Release v1.0.0-core: Clean Multi-Tenant SaaS Boilerplate & Reference Architecture"

# Dorong tag ke remote GitHub
git push origin v1.0.0-core
```

---

## 3. Alur Pengembang Pengguna Template

Setelah repositori dijadikan template:
1. Pengembang klik **"Use this template"** -> **"Create a new repository"**.
2. Beri nama repositori baru (misal: `my-saas-klinik`).
3. Klon repositori baru mereka secara lokal:
   ```bash
   git clone https://github.com/org/my-saas-klinik.git
   cd my-saas-klinik
   ```
4. Pasang dependensi dan lakukan instalasi satu langkah:
   ```bash
   composer install
   npm install
   php aio app:install
   ```
5. Mulai jalankan aplikasi via dev server:
   ```powershell
   powershell -ExecutionPolicy Bypass -File ./scripts/dev.ps1
   ```
