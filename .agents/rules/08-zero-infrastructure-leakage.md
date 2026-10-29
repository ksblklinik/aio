# Rule 08: Zero Infrastructure Leakage

## Tujuan
Mencegah degradasi arsitektur, duplikasi kode, dan kebocoran logika platform/infrastruktur (*leaky abstractions*) ke dalam modul bisnis atau modul fungsional.

## Ketentuan Utama

1. **Pemisahan Tegas Antara Domain Bisnis & Fondasi Platform**:
   - Direktori `core/` bertanggung jawab atas kapabilitas sistem, infrastruktur teknis, database, queue, events, auth engine, dan protokol komunikasi.
   - Direktori `app/Modules/<Business>` HANYA bertanggung jawab atas logika bisnis domain spesifik (misal: entitas, workflow bisnis, UI/controller domain tersebut).

2. **Topologi 3 Lapis & Alur Dependensi Searah (Skill [`codebase-design`](file:///c:/aplikasi/aio/.agents/skills/codebase-design/SKILL.md))**:
   - **Level 1 (Core Platform Kernel)**: `core/` (Auth, Multi-Tenant, Reconciler, Outbox, Mutex, Profiler).
   - **Level 2 (Universal Shared Foundations)**: `app/Modules/*` tingkat fondasi bersama (Contact, Finance, Inventory, Billing, Facility, Schedule, Tariff, Terminology, Seal).
   - **Level 3 (Specialized Domain Modules)**: `app/Modules/*` tingkat vertikal spesifik (EMR/Klinis, Farmasi Racikan, Posko Bencana, Lab).
   - Alur dependensi wajib searah ke bawah (Level 3 $\rightarrow$ Level 2 $\rightarrow$ Level 1). Core dan Level 2 **dilarang bergantung ke atas** ke Level 3. Modul sesama Level 3 **dilarang memiliki ketergantungan melingkar (*zero circular coupling*)**.

3. **Larangan Tambal Lokal (Anti-Local Patching)**:
   - Dilarang membuat logika infrastruktur umum (misal: custom event dispatcher, queue worker mandiri, database connection wrapper alternatif, atau auth helper ad-hoc) di dalam folder modul bisnis.
   - Dilarang melakukan query SQL langsung atau manipulasi tabel internal milik modul lain tanpa melalui kontrak/service yang sah atau domain event.

4. **Protokol Eskalasi Saat Menemukan Lubang Fondasi (Foundation Gap)**:
   - Jika saat merancang atau membangun modul bisnis ditemukan kebutuhan kapabilitas platform yang belum tersedia di `core/`:
     1. **STOP KODE BISNIS**: Jangan memaksakan membuat workaround di modul bisnis.
     2. **ESKALASI**: Laporkan gap fondasi tersebut secara transparan kepada pengguna.
     3. **FONDASI TERLEBIH DAHULU**: Buat atau perbarui spesifikasi teknis untuk memperkuat `core/` (baik sebagai spec mandiri atau fase pre-requisite) sebelum melanjutkan pengerjaan modul bisnis.

5. **Kepatuhan Terhadap Manifesto Modul**:
   - Setiap folder yang berada di dalam `app/Modules/` WAJIB memiliki `module.json` standar dan struktur rute `routes.php` yang terisolasi.

6. **Portabilitas & Netralitas Engine Database Modul Bisnis**:
   - Modul bisnis (`app/Modules/*`) HANYA boleh berkomunikasi dengan database melalui `Core\Database\Connection` dan wajib menggunakan standar SQL netral (*ANSI SQL*) atau helper query yang disediakan Core.
   - Dilarang keras meng-hardcode pemanggilan driver spesifik (misal: koneksi Redis mentah, fungsi khusus SQLite/Postgres proprietary) di dalam modul bisnis.
   - Hal ini menjamin saat Core mengaktifkan adapter PostgreSQL, MySQL, atau Redis di masa depan, modul bisnis tetap portabel dan kompatibel tanpa perlu penulisan ulang (*zero rewrite*).
