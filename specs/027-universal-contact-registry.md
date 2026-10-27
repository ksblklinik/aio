# SPEC-027: Universal Contact & Role Registry

- **Status**: `Completed`
- **Owner / Author**: Antigravity Platform Team
- **Tanggal Dibuat**: 2026-10-27
- **Target Modul**: `app/Modules/Contact/`
- **Tingkat Arsitektur**: `Level 2 (Shared Business Foundation)`

---

## 1. Problem Statement & Context
Dalam sistem modular multi-tenant (SaaS/ERP), setiap fungsi bisnis—seperti Kasir (POS), Klinik (Kesehatan), Pembelian (Procurement), Proyek, dan Penagihan (Invoicing)—selalu membutuhkan data entitas manusia (perorangan) dan badan hukum (organisasi).

Jika data kontak dibangun secara terisolasi di setiap modul vertikal, akan timbul masalah:
1. **Redundansi & Inkonsistensi Data**: Entitas yang sama (misal seorang dokter yang juga membeli obat di apotek, atau supplier yang juga menjadi pelanggan) tersimpan di dua tabel berbeda dengan data yang rawan tidak sinkron.
2. **Ketiadaan Standarisasi Keamanan PII**: Informasi pribadi yang dapat diidentifikasi (Personally Identifiable Information / PII) seperti NIK dan NPWP sering disimpan sebagai plaintext tanpa enkripsi.
3. **Keterikatan Kaku (*Tight Coupling*)**: Modul kasir atau klinik menjadi bergantung pada skema CRM tertentu, melanggar prinsip *domain-agnostic*.

Solusi arsitekturalnya adalah membangun **Modul Level 2: `Contact` (Universal Contact & Role Registry)** yang agnostik, menggunakan pola baku industri open source (*Party-Role Pattern* yang disederhanakan), dan bertindak sebagai *Single Source of Truth* untuk seluruh entitas kontak di dalam tenant.

---

## 2. Goals & Non-Goals

### Goals
- **Universal Data Model (`Individual` vs `Organization`)**:
  - Mendukung kontak berupa individu manusia (*Person*) maupun badan usaha / instansi (*Organization*).
- **Dynamic Role Tagging (`contact_roles`)**:
  - Satu kontak dapat mengemban banyak peran dinamis sekaligus (misal: `customer`, `vendor`, `employee`, `patient`, `practitioner`, `member`) tanpa duplikasi entitas induk.
- **Enterprise PII Security**:
  - Nomor identitas nasional (NIK/KTP/Paspor) dan nomor pokok wajib pajak (NPWP/Tax ID) otomatis dienkripsi dan didekripsi menggunakan engine authenticated `AES-256-GCM` (`encrypt()` dan `decrypt()`).
- **Autonomous Numbering & Dynamic Fields**:
  - Kode kontak otomatis berurut per tenant via helper `sequence('contact')` (e.g., `CNT-2026-00001`).
  - Dukungan field dinamis tak terbatas melalui helper `custom_fields('contact')`.
- **Multi-Address & Geo-Coordination (`contact_addresses`)**:
  - Mendukung banyak alamat per kontak (tagging: `primary`, `billing`, `shipping`, `office`, `home`) beserta latitude/longitude untuk pemetaan GPS.
- **Affiliation & Contact Relations (`contact_relations`)**:
  - Menghubungkan kontak individu dengan organisasi induknya (misal: Budi Santoso menjabat sebagai *Manager Pembelian* di *PT Maju Mundur*).
- **Exported Shared Contract Interface (`ContactRegistryInterface`)**:
  - Mengekspos interface service yang bersih dan decoupled untuk dikonsumsi oleh modul Level 3 mana pun.
- **Modern User Interface & REST API**:
  - Antarmuka web responsif dan endpoint JSON REST API untuk pencarian autocomplete instan, pembuatan, dan pengeditan kontak.

### Non-Goals
- Tidak memasukkan fitur sales pipeline, leads tracking, atau funnel marketing (karena itu merupakan domain modul CRM komersial tersendiri).

---

## 3. Database Schema Design

```
┌─────────────────────────────────────────────────────────────────┐
│                           contacts                              │
├─────────────────────────────────────────────────────────────────┤
│ id (PK, int)                                                    │
│ tenant_id (FK, int)                                             │
│ type ('individual' | 'organization')                            │
│ code (varchar, unique per tenant) e.g. CNT-2026-00001           │
│ name (varchar, display name)                                    │
│ legal_name (varchar, nullable)                                  │
│ id_number (text, encrypted AES-256-GCM)                         │
│ tax_id (text, encrypted AES-256-GCM)                            │
│ email (varchar, nullable)                                       │
│ phone (varchar, nullable)                                       │
│ mobile (varchar, nullable - WhatsApp)                           │
│ status ('active' | 'inactive' | 'archived')                     │
│ notes (text, nullable)                                          │
│ created_at, updated_at, deleted_at (soft delete)                │
└────────────────┬────────────────┬───────────────────────────────┘
                 │ 1:N            │ 1:N
                 ▼                ▼
┌────────────────────────┐      ┌─────────────────────────────────┐
│     contact_roles      │      │        contact_addresses        │
├────────────────────────┤      ├─────────────────────────────────┤
│ id (PK)                │      │ id (PK)                         │
│ contact_id (FK)        │      │ contact_id (FK)                 │
│ role (varchar)         │      │ type ('primary','billing',...)  │
│ is_primary (bool)      │      │ address_line1, address_line2    │
│ metadata (json)        │      │ city, province, postal_code     │
│ created_at             │      │ latitude, longitude             │
└────────────────────────┘      │ is_default (bool)               │
                                └─────────────────────────────────┘
                 │ 1:N
                 ▼
┌─────────────────────────────────┐
│       contact_relations         │
├─────────────────────────────────┤
│ id (PK)                         │
│ parent_contact_id (FK - Org)    │
│ child_contact_id (FK - Person)  │
│ relation_type ('employee_of',..│
│ job_title (varchar, nullable)   │
│ created_at                      │
└─────────────────────────────────┘
```

---

## 4. Technical Architecture & Shared Contract

### Service Interface: `ContactRegistryInterface`
```php
namespace App\Modules\Contact\Contracts;

use App\Modules\Contact\Models\Contact;

interface ContactRegistryInterface
{
    public function create(array $attributes): Contact;
    public function update(int $id, array $attributes): Contact;
    public function find(int $id): ?Contact;
    public function findByCode(string $code): ?Contact;
    public function delete(int $id): bool;
    
    // Role Management
    public function getByRole(string $role, array $filters = []): array;
    public function addRole(int $contactId, string $role, array $metadata = []): void;
    public function removeRole(int $contactId, string $role): void;
    public function hasRole(int $contactId, string $role): bool;

    // Search & Directory
    public function search(string $query, int $limit = 20): array;
    
    // Addresses & Affiliations
    public function addAddress(int $contactId, array $addressData): int;
    public function linkAffiliation(int $personId, int $organizationId, string $relationType, ?string $jobTitle = null): int;
}
```

---

## 5. Test Strategy
- **Unit Testing**:
  - `tests/Unit/ContactModuleTest.php`:
    - Menguji pembuatan kontak tipe `individual` dan `organization`.
    - Menguji pembangkitan kode otomatis via `sequence('contact')`.
    - Menguji enkripsi dan dekripsi otomatis nomor identitas (NIK/NPWP) dengan cipher AES-256-GCM.
    - Menguji penetapan multi-role (`customer`, `vendor`, dll) pada satu kontak dan query filter `getByRole()`.
    - Menguji penambahan alamat fisik dan tautan relasi afiliasi (*Person* bekerja di *Organization*).
    - Menguji isolasi data multi-tenant (kontak Tenant A tidak bocor ke Tenant B).
- **Integration Verification**:
  - Eksekusi `./scripts/verify.ps1` (verifikasi seluruh suite, `tsc --noEmit`, dan pre-commit audit).

---

## 6. Atomic Task Checklist

- [x] **Phase 1: Module Scaffolding & Database Migration**
  - [x] 1.1 Buat manifest modul `app/Modules/Contact/module.json` dan daftarkan provider `ContactServiceProvider`.
  - [x] 1.2 Buat file migrasi SQL: `2026_10_27_000021_create_contacts_tables.sql` (`contacts`, `contact_roles`, `contact_addresses`, `contact_relations`).
  - [x] 1.3 Jalankan migrasi via `php aio migrate`.
- [x] **Phase 2: Domain Models & Contract Definition**
  - [x] 2.1 Buat interface kontrak `App\Modules\Contact\Contracts\ContactRegistryInterface`.
  - [x] 2.2 Buat model `Contact`, `ContactRole`, `ContactAddress`, dan `ContactRelation` dengan tenant scoping & PII mutator encryption.
- [x] **Phase 3: Service Layer & Implementation**
  - [x] 3.1 Implementasikan `App\Modules\Contact\Services\ContactService` yang mengimplementasikan `ContactRegistryInterface`.
  - [x] 3.2 Daftarkan singleton service di container aplikasi dan sediakan global helper `contacts(): ContactRegistryInterface`.
- [x] **Phase 4: Web UI & REST API Controllers**
  - [x] 4.1 Buat `ContactController` dan view antarmuka manajemen kontak (`index.php`, form drawer/modal).
  - [x] 4.2 Buat endpoint REST API `/api/contacts` untuk autocomplete & pencarian data instan oleh modul lain.
- [x] **Phase 5: Unit Testing & Self-Verification**
  - [x] 5.1 Buat unit test komprehensif di `tests/Unit/ContactModuleTest.php`.
  - [x] 5.2 Jalankan `./scripts/verify.ps1`, pastikan seluruh test lolos 100%.
  - [x] 5.3 Lakukan atomic commit dan merge branch fitur ke `main`.
  - [x] 5.4 Perbarui dokumentasi spec dan `SESSION_HANDOFF.md`.
