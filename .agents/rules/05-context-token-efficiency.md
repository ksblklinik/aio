# Rule 05: Context Window & Token Efficiency

## 1. Filosofi Utama
Efisiensi token diterapkan untuk **menjaga ketajaman daya ingat (attention retention) model** dan mencegah fenomena *Lost in the Middle*. Efisiensi dilakukan pada **metode interaksi data**, BUKAN dengan mengurangi kelengkapan spesifikasi atau menghilangkan pengujian.

---

## 2. Praktik Pembacaan File Terarah (Surgical Reading & Repo-Map)
- **Konsultasikan Peta Simbol Terlebih Dahulu (.agents/REPO_MAP.md)**:
  - Sebelum menjelajahi file-file kode secara manual untuk memahami arsitektur, baca file [.agents/REPO_MAP.md](file:///c:/aplikasi/jstudio/.agents/REPO_MAP.md). Peta ini memuat ringkasan class, interface, fungsi, dan method seluruh codebase secara padat token.
  - Untuk memperbarui peta kode kapan saja, jalankan:
    ```powershell
    powershell -ExecutionPolicy Bypass -File ./scripts/map.ps1
    ```
- **Cari Dulu, Baca Kemudian**:
  - Jangan membaca file ratusan baris secara penuh jika hanya mencari fungsi atau variabel tertentu.
  - Gunakan `grep_search` untuk menemukan nomor baris spesifik terlebih dahulu.
- **Slice Window Reading**:
  - Saat menggunakan `view_file`, tentukan `StartLine` dan `EndLine` secara presisi (contoh: baca 30-50 baris sekitar target, bukan 800 baris penuh).

---

## 3. Praktik Modifikasi Kode (Surgical Editing)
- **Gunakan Diffs/Replacements Lokal**:
  - Gunakan `replace_file_content` untuk mengubah blok kode yang dituju.
  - **Dilarang** menulis ulang (overwrite) file utuh berukuran besar hanya untuk menambahkan 1-2 baris import atau mengubah satu parameter.
- **Minimal Diffs**:
  - Jangan melakukan auto-format atau linting kosmetik pada baris-baris kode yang tidak berhubungan dengan sub-tugas yang sedang dikerjakan.

---

## 4. Dokumen Spec Sebagai Jangkar Memori Eksternal
- **Single Source of Truth**:
  - File `specs/<nomor>-<nama>.md` adalah penyimpan *state* utama. Progres tugas dilacak via checkbox `- [x]`.
- **Session Independence**:
  - Karena progres tersimpan di file fisik disk, agen tidak memerlukan riwayat chat masa lalu yang panjang untuk mengetahui posisi pengerjaan. Cukup membaca spec target dan langsung melanjutkan tugas `- [ ]` berikutnya.

---

## 5. Ringkas Output Terminal & Log
- Hindari menjalankan perintah yang memuntahkan ribuan baris log ke konteks tanpa filter.
- Jika sebuah perintah berpotensi menghasilkan output sangat masif (misal `git log`, audit dependensi, atau full build log), batasi output (contoh: `git log -n 5`, atau pipe/filter error spesifik).

---

## 6. Batasan Mutlak: Hal yang HARAM Dipangkas
Efisiensi token **TIDAK BOLEH** mengorbankan kualitas rekayasa perangkat lunak:
1. **Dilarang menyingkat dokumen spesifikasi**: Penjelasan *edge cases*, tipe data, skema, dan batasan arsitektur harus tetap lengkap dan presisi.
2. **Dilarang melewatkan penulisan test**: Test unit dan integrasi adalah jaring pengaman utama.
3. **Dilarang menghapus komentar dokumentasi atau kode penjelas penting**.
