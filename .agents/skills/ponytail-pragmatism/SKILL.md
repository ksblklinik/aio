---
name: ponytail-pragmatism
description: Menerapkan prinsip Project Ponytail (Dietrich Gebert) untuk mencegah over-engineering, memangkas bloatware, dan menuntut solusi minimalis yang mengutamakan fungsi native PHP/SQL sebelum menulis kode baru.
---

# Skill: Ponytail Pragmatism & Anti-Bloat Review

Gunakan skill ini saat:
1. Merancang arsitektur atau solusi teknis di tahap **Spec & Plan** (Phase 2).
2. Melakukan audit kode sebelum commit (**Ponytail Diff Audit**).
3. Mendeteksi kode yang terasa terlalu rumit, panjang, atau memiliki lapisan abstraksi berlebih (*code smell*).

> **Harmonisasi Arsitektur**:
> * Skill ini berjalan seiring dengan [**`codebase-design`**](file:///c:/aplikasi/aio/.agents/skills/codebase-design/SKILL.md) (Prinsip Deep Modules John Ousterhout).
> * Modul publik wajib menyediakan satu deep entry point (`Contracts/*RegistryInterface.php`), namun bagian internal modul dilarang keras membuat interface semu, DTO berlapis, atau rantai repository/manager perantara yang dangkal.

---

## 1. Sikap Mental Ponytail (The Pragmatic Senior Mindset)

> *"Lazy about solutions, relentless about investigation."*

- **Saat Investigasi (Relentless)**: Baca berkas secara seksama, telusuri alur pemanggilan data, pelajari relasi database hingga ke migrasi asli. Jangan membuat asumsi liar.
- **Saat Eksekusi (Lazy / Minimalist)**: Berusaha sekeras mungkin untuk **TIDAK** menulis kode baru jika masalah bisa diselesaikan dengan:
  - Mengubah 1 kondisi query SQL.
  - Memakai fungsi bawaan PHP 8.3 (`array_column`, `array_map`, `in_array`, `match`, dsb).
  - Memanfaatkan service/helper yang sudah ada di proyek.

---

## 2. Checklist Ponytail Diff Audit (Sebelum Commit)

Sebelum membuat git commit, lakukan inspeksi terhadap baris yang Anda ubah (`git diff`):

- [ ] **Line Count Justification**: Bisakah perubahan ini dicapai dengan setengah dari jumlah baris yang ditulis?
- [ ] **Premature Abstraction Check**: 
  - Apakah Anda baru saja membuat `Interface` internal untuk kelas di modul yang sama? (Jika ya, hapus interfacenya dan gunakan kelas langsung. Kontrak interface HANYA diperuntukkan bagi batas publik modul di `Contracts/*RegistryInterface.php` sesuai [`codebase-design`](file:///c:/aplikasi/aio/.agents/skills/codebase-design/SKILL.md)).
  - Apakah Anda membuat lapisan perantara tipis (misal: Service $\rightarrow$ Manager $\rightarrow$ Repository $\rightarrow$ DAO yang hanya meneruskan parameter)? (Jika ya, satukan langsung ke dalam Deep Module Service).
- [ ] **Native Capability Check**:
  - Apakah Anda menulis loop manual untuk memfilter array? Ganti dengan `array_filter` atau query SQL bersyarat langsung.
  - Apakah Anda menghitung saldo/stok di memori PHP lalu update? Ganti dengan kalkulasi atomik SQL (`SET stock = stock - ? WHERE stock >= ?`).
- [ ] **No Dead Code / Speculative Code**:
  - Apakah ada parameter atau opsi fungsi yang belum dipakai di mana pun? Hapus.
  - Apakah ada komentar `// nanti akan dipakai jika fitur X dibuat`? Hapus.
- [ ] **Non-Negotiables Intact**:
  - Pastikan tenant guard (`tenant_id`), transaksi database, penanganan error, dan test suite tetap terlindungi 100%.

---

## 3. Heuristik Ponytail vs Kebiasaan Buruk AI

| Kebiasaan Buruk AI (Bloat) | Pendekatan Ponytail (Minimalis & Elegan) |
|---|---|
| Membuat 5 file baru (Interface, DTO, Repository, Service, Custom Exception) untuk 1 query sederhana. | 1 method ringkas di Service yang sudah ada menggunakan SQL langsung via `Connection`. |
| Menambahkan package library baru untuk tugas sepele (misal: parsing format tanggal atau regex). | Pakai `DateTimeImmutable` atau fungsi string/regex bawaan PHP. |
| Melakukan kalkulasi logika di memori PHP lalu menimpa database (rawan *race condition*). | Serahkan ke engine SQLite dengan klausa atomik dan indeks yang tepat. |
| Menulis boilerplate CSS puluhan baris untuk komponen baru. | Gunakan semantic CSS design tokens yang sudah tersedia di root CSS. |
