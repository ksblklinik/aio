# SPEC-005: Universal Taxonomy & Domain-Agnostic Modular Governance

- **Status**: `Done`
- **Owner / Author**: aio Framework Team
- **Tanggal Dibuat**: 2026-10-21
- **Target File / Modul**: `core/Modules`, `resources/js`, `app/Modules/*`

---

## 1. Problem Statement & Context
Sebelumnya, sistem kategori modul bersifat teks bebas (*free-text string*) dengan orientasi bisnis profit sempit ("Bisnis", "Sales", "Invoicing"). Hal ini menyebabkan:
1. Inkonsistensi data ketika modul baru dibuat.
2. Kesan platform hanya dapat dipakai oleh korporasi/startup profit, padahal arsitektur multi-tenancy & modular dapat melayani organisasi nirlaba, sekolah, yayasan sosial, RT/RW, komunitas, hingga instansi publik.

## 2. Goals & Non-Goals
### Goals
- [x] Membangun `CategoryRegistry` terpusat dengan 8 taksonomi organisasi universal (*Organizational Archetypes*).
- [x] Standardisasi manifest `module.json` yang ada (`Auth`, `Workspaces`, `Modules`, `Projects`).
- [x] Mengubah form input kategori pada modal scaffold di UI menjadi dropdown baku (*standardized select*).
- [x] Menampilkan filter pills dan label kategori dengan warna semantik yang konsisten dan informatif.
- [x] Menyesuaikan terminologi antarmuka agar agnostik terhadap domain profit maupun non-profit.
- [x] Unit test komprehensif untuk memvalidasi integritas taksonomi.

### Non-Goals
- Mengubah arsitektur database multi-tenancy atau tabel modul yang sudah berjalan.

## 3. Technical Architecture & Design

### Standardized Taxonomy Table
| Category ID | Label / Nama Resmi | Icon | Deskripsi Domain |
| :--- | :--- | :--- | :--- |
| `core` | Core & Keamanan | `ShieldCheck` | Fondasi Keamanan, Akun, Multi-Tenancy & Pengaturan Sistem |
| `collaboration` | Kolaborasi & Alur Kerja | `Layers` | Alur Kerja, Agenda, Inisiatif/Proyek, Penugasan, Disposisi |
| `people` | Anggota & Keanggotaan | `Users` | Direktori Anggota/Warga/Relawan/Siswa, Profil, Presensi |
| `knowledge` | Pengetahuan & Dokumen | `BookOpen` | Arsip Dokumen, Wiki/SOP, Publikasi, Perpustakaan |
| `resources` | Sumber Daya & Finansial | `Coins` | Kas Organisasi, Iuran Anggota, Donasi Publik, Invoice/Billing |
| `engagement` | Komunikasi & Pengumuman | `MessageSquare` | Forum Diskusi, Siaran Pengumuman, Notifikasi Pesan |
| `analytics` | Analitik & Laporan | `BarChart3` | Laporan Pertanggungjawaban (LPJ), Metrik & Statistik |
| `developer` | Integrasi & Ekstensi | `Code2` / `Boxes` | Webhooks, REST API Keys, Integrasi Pihak Ketiga |

---

## 4. Atomic Task Checklist

- [x] **Phase 1: Core CategoryRegistry Engine**
  - [x] 1.1 Buat class `Core\Modules\CategoryRegistry` lengkap dengan metadata (id, label, icon, color, description) dan metode accessor.
  - [x] 1.2 Update `Core\Modules\ModuleManager` agar memanfaatkan `CategoryRegistry` saat sync manifest dan scaffolding modul baru.

- [x] **Phase 2: Manifest Standardization & Data Alignment**
  - [x] 2.1 Selaraskan `module.json` seluruh modul yang ada (`Auth`, `Workspaces`, `Modules`, `Projects`) ke ID kategori baku.
  - [x] 2.2 Sinkronkan kembali database lokal `database.sqlite` dengan kategori terstandarisasi.

- [x] **Phase 3: Controller & UI Enhancement**
  - [x] 3.1 Bagikan daftar taksonomi kategori resmi ke Inertia props di `ModuleManagerController.php`.
  - [x] 3.2 Update modal scaffold di `resources/js/Pages/Modules/Index.tsx` dengan dropdown `<select>` kategori baku.
  - [x] 3.3 Update filter pills dan badge kategori pada kartu modul dengan warna & icon semantik.

- [x] **Phase 4: Automated Testing & Verification**
  - [x] 4.1 Tulis unit test `tests/Unit/CategoryRegistryTest.php`.
  - [x] 4.2 Jalankan full test suite `vendor/bin/phpunit` (70/70 passing).
  - [x] 4.3 Jalankan frontend build `npm run build` (tsc & vite zero errors).
  - [x] 4.4 Perbarui `specs/README.md` dan `.agents/SESSION_HANDOFF.md`.
  - [x] 4.5 Atomic Git commit.
