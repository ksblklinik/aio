# SPEC-003: Dynamic Module Manager & Plugin System

- **Status**: `In Review`
- **Owner / Author**: AI Agent & Lead Developer
- **Tanggal Dibuat**: 2026-10-21
- **Target File / Modul**: `Core\Modules\ModuleManager`, `app/Modules/`, `resources/js/Pages/Modules/`

---

## 1. Problem Statement & Context
Saat ini, penambahan, penonaktifan, atau penghapusan modul di aplikasi `aio` masih dilakukan secara manual melalui hardcoding di [routes/web.php](file:///c:/aplikasi/aio/routes/web.php) dan array navigasi [resources/js/Layouts/AppLayout.tsx](file:///c:/aplikasi/aio/resources/js/Layouts/AppLayout.tsx).

Hal ini menimbulkan beberapa kendala:
1. Mengharuskan developer mengedit file inti aplikasi setiap kali menambah atau mematikan modul.
2. Risiko kesalahan sintaks atau terhapusnya route inti saat modifikasi file route global.
3. Tidak ada dashboard visual bagi Administrator untuk melihat modul apa saja yang terpasang dan statusnya (*Active/Disabled*).

---

## 2. Goals & Non-Goals

### Goals
- [ ] Menyediakan `module.json` manifesto standar di setiap folder modul (`app/Modules/<Name>/module.json`).
- [ ] Menyediakan layanan backend `Core\Modules\ModuleManager` untuk:
  - Memindai daftar modul yang tersedia (`listModules`).
  - Mengaktifkan / menonaktifkan modul (`toggleActive`).
  - Membuat modul baru dengan struktur otomatis (*scaffold template*).
  - Menghapus modul non-core secara aman (*delete module*).
- [ ] Mendukung **Dynamic Route Loading**: Router utama memuat rute dari `app/Modules/<Name>/routes.php` hanya jika modul dalam status aktif.
- [ ] Mendukung **Dynamic Navigation Bar**: Navigasi sidebar merender menu dari daftar modul aktif via Inertia Shared Props.
- [ ] Menyediakan antarmuka UI Dashboard **Module Manager** di `/modules` (khusus role Owner/Admin).
- [ ] Proteksi Modul Core (`is_core: true`): Modul inti seperti `Auth`, `Workspaces`, dan `ModuleManager` dilindungi dari penonaktifan/penghapusan.

### Non-Goals
- Tidak mencakup instalasi modul langsung dari repositori git remote atau marketplace cloud di iterasi ini (fokus pada modul lokal terlebih dahulu).
- Tidak mencakup isolasi modul per tenant (semua modul berlaku global di level aplikasi pada versi ini).

---

## 3. Technical Architecture & Design

### Data Flow
```
[Admin UI: /modules] 
       │
       ▼ (HTTP POST /modules/toggle, /modules/create, /modules/delete)
[Core\Modules\ModuleManager] 
       │
       ├─► Baca/Tulis: config/modules.json (Registry State)
       ├─► Baca/Validasi: app/Modules/*/module.json (Manifesto)
       └─► File System Ops: Scaffold folder atau Safe Delete
       │
       ▼ (Inertia Shared Props: 'active_modules')
[AppLayout.tsx: Dynamic Sidebar Navigation]
```

### Manifesto Modul (`app/Modules/<Name>/module.json`)
```json
{
  "id": "projects",
  "name": "Proyek & Tugas",
  "description": "Manajemen proyek dan checklist tugas multi-tenant",
  "version": "1.0.0",
  "icon": "Layers",
  "route": "/projects",
  "is_core": false,
  "is_active": true
}
```

### Registry State (`system_modules` Database Table)
Status aktif/nonaktif modul disimpan secara persisten di tabel database `system_modules`:
```sql
CREATE TABLE IF NOT EXISTS system_modules (
    id TEXT PRIMARY KEY,           -- slug unik: 'projects', 'invoicing'
    name TEXT NOT NULL,           -- 'Proyek & Tugas'
    description TEXT NULL,
    version TEXT NOT NULL DEFAULT '1.0.0',
    icon TEXT NOT NULL DEFAULT 'Layers',
    route TEXT NOT NULL,          -- '/projects'
    is_core INTEGER NOT NULL DEFAULT 0, -- 1 = dilindungi dari hapus/nonaktif
    is_active INTEGER NOT NULL DEFAULT 1,
    table_name TEXT NULL,         -- nama tabel utama jika ingin di-clean wipe
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### Kebijakan Penghapusan Modul (Clean Wipe Policy)
- **Modul Core (`is_core = 1`)**: Dilindungi penuh dari penonaktifan dan penghapusan.
- **Modul Non-Core**: Saat dihapus, sistem menjalankan **Clean Wipe**:
  1. Hapus record dari `system_modules`.
  2. Eksekusi `DROP TABLE IF EXISTS <table_name>` untuk membersihkan data modul di database.
  3. Hapus folder backend `app/Modules/<Name>/`.
  4. Hapus folder frontend `resources/js/Pages/<Name>/`.

### Dynamic Router Loader
Di `routes/web.php`:
```php
$moduleManager = app(Core\Modules\ModuleManager::class);
foreach ($moduleManager->getActiveModules() as $module) {
    $moduleRouteFile = dirname(__DIR__) . "/app/Modules/{$module['folder']}/routes.php";
    if (file_exists($moduleRouteFile)) {
        require $moduleRouteFile;
    }
}
```

---

## 4. Edge Cases & Error Scenarios
- **Percobaan Hapus Modul Core**: Mengembalikan flash error *"Modul core (Auth/Workspaces) tidak dapat dihapus atau dinonaktifkan."*
- **Nama Modul Duplikat saat Buat Baru**: Validasi gagal jika folder `app/Modules/<Name>` sudah ada.
- **Nama Modul Tidak Valid**: Sanitasi nama modul (hanya alphanumeric dan PascalCase/kebab-case).
- **File Routes Rusak pada Modul**: Exception ditangkap dengan aman tanpa membuat seluruh aplikasi crash.

---

## 5. Test Strategy
- **Unit Tests**:
  - `ModuleManagerTest.php`:
    - Uji scanning direktori dan pembacaan `module.json`.
    - Uji pengubahan status aktif/nonaktif dan persistensi di `config/modules.json`.
    - Uji proteksi modul core agar tidak bisa dihapus.
    - Uji pembuatan (*scaffold*) template modul baru.
    - Uji penghapusan modul non-core.
  - `DynamicRoutingTest.php`:
    - Uji rute aktif dapat diakses (200/302).
    - Uji rute modul yang dinonaktifkan mengembalikan 404.

---

## 6. Atomic Task Checklist

- [x] **Phase 1: Database Migration, Manifest & ModuleManager Engine (TDD)**
  - [x] 1.1 Buat skema migrasi database `database/migrations/2026_10_21_000002_create_system_modules_table.sql`.
  - [x] 1.2 Buat manifesto `module.json` untuk seluruh modul saat ini (`Auth`, `Workspaces`, `Projects`) dengan metadata lengkap (id, name, version, author, description, category, icon, route, dependencies, is_core).
  - [x] 1.3 Buat engine `Core\Modules\ModuleManager` lengkap dengan:
        - `syncFromFilesystem()`: sinkronisasi database dengan `module.json`.
        - `listModules()`: memuat seluruh modul dan status aktifnya.
        - `getActiveModules()` & `getActiveNavItems()`: memuat modul aktif untuk navigasi & rute.
        - `toggleActive($id)` dengan **Dependency Guard** (menolak nonaktif jika ada modul aktif lain yang membutuhkan).
        - `createModuleScaffold($data)`: generate template modul baru secara otomatis.
        - `deleteModule($id)`: clean wipe (drop table modul + hapus folder backend/frontend).
  - [x] 1.4 Buat unit test komprehensif `tests/Unit/ModuleManagerTest.php` untuk memvalidasi seluruh fungsi engine dan guard dependensi.

- [ ] **Phase 2: Dynamic Routing & Nav Bar Integration**
  - [ ] 2.1 Pindahkan rute projects ke `app/Modules/Projects/routes.php`.
  - [ ] 2.2 Integrasikan dynamic route loader di `routes/web.php` agar hanya memuat modul aktif.
  - [ ] 2.3 Bagikan daftar `active_modules` secara otomatis ke Inertia Shared Props.
  - [ ] 2.4 Perbarui `AppLayout.tsx` agar merender menu navigasi dinamis dari `props.active_modules`.

- [ ] **Phase 3: Module Manager Admin UI**
  - [ ] 3.1 Buat controller `app/Modules/Modules/Controllers/ModuleManagerController.php`.
  - [ ] 3.2 Buat halaman Inertia React `resources/js/Pages/Modules/Index.tsx` dengan tema Semantic Tokens.
  - [ ] 3.3 Pasang fitur toggle aktif/nonaktif interaktif dengan feedback status seketika.
  - [ ] 3.4 Pasang modal dialog tambah modul baru (*scaffold wizard*).
  - [ ] 3.5 Pasang tombol konfirmasi hapus modul non-core.

- [ ] **Phase 4: Verification & Git Handoff**
  - [ ] 4.1 Jalankan seluruh test suite (`vendor/bin/phpunit`).
  - [ ] 4.2 Jalankan build frontend (`npm run build`).
  - [ ] 4.3 Verifikasi manual di browser (uji toggle, tambah modul, dan hapus modul).
  - [ ] 4.4 Perbarui `specs/README.md` dan `.agents/SESSION_HANDOFF.md`.
  - [ ] 4.5 Atomic commit Git.
