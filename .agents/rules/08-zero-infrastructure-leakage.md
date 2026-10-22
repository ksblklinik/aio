# Rule 08: Zero Infrastructure Leakage

## Tujuan
Mencegah degradasi arsitektur, duplikasi kode, dan kebocoran logika platform/infrastruktur (*leaky abstractions*) ke dalam modul bisnis atau modul fungsional.

## Ketentuan Utama

1. **Pemisahan Tegas Antara Domain Bisnis & Fondasi Platform**:
   - Direktori `core/` bertanggung jawab atas kapabilitas sistem, infrastruktur teknis, database, queue, events, auth engine, dan protokol komunikasi.
   - Direktori `app/Modules/<Business>` HANYA bertanggung jawab atas logika bisnis domain spesifik (misal: entitas, workflow bisnis, UI/controller domain tersebut).

2. **Larangan Tambal Lokal (Anti-Local Patching)**:
   - Dilarang membuat logika infrastruktur umum (misal: custom event dispatcher, queue worker mandiri, database connection wrapper alternatif, atau auth helper ad-hoc) di dalam folder modul bisnis.
   - Dilarang melakukan query SQL langsung atau manipulasi tabel internal milik modul lain tanpa melalui kontrak/service yang sah atau domain event.

3. **Protokol Eskalasi Saat Menemukan Lubang Fondasi (Foundation Gap)**:
   - Jika saat merancang atau membangun modul bisnis ditemukan kebutuhan kapabilitas platform yang belum tersedia di `core/`:
     1. **STOP KODE BISNIS**: Jangan memaksakan membuat workaround di modul bisnis.
     2. **ESKALASI**: Laporkan gap fondasi tersebut secara transparan kepada pengguna.
     3. **FONDASI TERLEBIH DAHULU**: Buat atau perbarui spesifikasi teknis untuk memperkuat `core/` (baik sebagai spec mandiri atau fase pre-requisite) sebelum melanjutkan pengerjaan modul bisnis.

4. **Kepatuhan Terhadap Manifesto Modul**:
   - Setiap folder yang berada di dalam `app/Modules/` WAJIB memiliki `module.json` standar dan struktur rute `routes.php` yang terisolasi.
