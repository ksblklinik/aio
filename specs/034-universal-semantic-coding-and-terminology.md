# SPEC-034: Universal Semantic Coding & Terminology Registry

- **Status**: `Completed`
- **Owner / Author**: Antigravity Platform Team
- **Tanggal Dibuat**: 2026-10-28
- **Target Modul**: `app/Modules/Terminology/`
- **Level Arsitektur**: Level 2 (Universal Shared Business Foundation)

---

## 1. Problem Statement & Context

Dalam sistem enterprise multi-sektor modern—termasuk **Klinik / FKTP / Rumah Sakit**, **Logistik & Ekspor-Impor**, **Pengadaan Barang (E-Procurement)**, **Perpajakan & Legalitas**, serta **Sektor Finansial**:
1. **Entri Data Tidak Boleh Berupa Teks Bebas Tak Terstruktur**:
   - Dalam rekam medis, diagnosis penyakit tidak boleh hanya diketik bebas tanpa kode standar (misal: "flu", "pilek", "batuk"), melainkan harus terikat pada standar **ICD-10** (WHO / Kemenkes) dan tindakan ke **ICD-9-CM** untuk keperluan klaim BPJS, asuransi, dan interoperabilitas SatuSehat (Kemenkes FHIR).
   - Dalam perdagangan internasional dan logistik bea cukai, barang wajib memiliki **HS Code** (*Harmonized System Code*) resmi.
   - Dalam perizinan usaha dan klasifikasi bisnis, kegiatan usaha terikat pada **KBLI** (*Klasifikasi Baku Lapangan Usaha Indonesia*).
   - Dalam laboratorium, parameter tes terikat pada standar **LOINC**.
2. **Celah Fragmentasi Data Lintas Modul (*Infrastructure Leakage*)**:
   - Jika engine terminologi dan kamus standar eksternal ini tidak dibangun di Level 2, setiap modul bisnis (Rekam Medis, Modul Bridging SatuSehat FHIR, Modul BPJS PCare, Modul Invoicing Pajak/Faktur) akan terpaksa membuat tabel kamus lokal sendiri.
3. **Kebutuhan Pemetaan Kode Lintas Sistem (*Cross-Reference Mapping*)**:
   - Kode internal klinik/perusahaan seringkali berbeda dengan kode BPJS atau kode FHIR SatuSehat. Sistem membutuhkan *cross-reference engine* untuk memetakan kode internal $\leftrightarrow$ kode BPJS $\leftrightarrow$ kode standar internasional secara otomatis.

Modul `Terminology` hadir sebagai fondasi universal Level 2 untuk menyimpan katalog sistem kodifikasi, jutaan konsep/kode resmi, sinonim pencarian ramah-pengguna, dan pemetaan relasi antar-sistem.

---

## 2. Goals & Non-Goals

### Goals
- [x] Menyediakan **Code Systems Registry** (`terminology_code_systems`) untuk mendaftarkan otoritas kamus standar (ICD-10, ICD-9-CM, LOINC, KFA, HS-Code, KBLI, ISO-4217, SNOMED) beserta URI identifikasi resmi dan status global/tenant.
- [x] Menyediakan **Concept Codes Dictionary** (`terminology_concepts`):
  - Menyimpan kode, display name (*preferred term*), deskripsi, dan relasi hierarki (*parent code*).
  - Menyimpan kata kunci sinonim (*synonyms*) pencarian cepat dalam bahasa lokal (misal: mencari "demam berdarah" menemukan kode `A91 Dengue haemorrhagic fever`).
- [x] Menyediakan **Cross-Reference Mapping Engine** (`terminology_mappings`):
  - Menghubungkan kode antar sistem kodifikasi (misal: Kode Internal $\leftrightarrow$ Kode BPJS $\leftrightarrow$ Kode SatuSehat) dengan atribut kesetaraan (`equivalent`, `narrower`, `wider`, `related_to`).
- [x] Menyediakan **Fast Autocomplete & Search Engine**:
  - Pencarian konsep instan berdasarkan kode, nama, atau sinonim dengan batasan sistem kodifikasi dan limit.
- [x] Menyediakan Contract Interface `TerminologyRegistryInterface`, Global Helper `terminology()`, dan REST API endpoints untuk integrasi auto-complete pada antarmuka pengguna.
- [x] Antarmuka manajemen Web Inertia React (`resources/js/Pages/Terminology/Index.tsx`) dengan tema dark/light mode baku.

### Non-Goals
- Modul ini **TIDAK** membungkus resource FHIR Bundle utuh (transformasi JSON FHIR R4 dilakukan oleh adaptor bridging SatuSehat di modul bisnis).
- Modul ini **TIDAK** meng-hardcode 50.000 baris kamus langsung di file migrasi SQL dasar; data diinjeksi via seeder kurasi standar dan fitur import bulk CSV/JSON.

---

## 3. Technical Architecture & Design

### Alur Integrasi Lintas Modul
```
[Dokter / User Form / Kiosk]
       │ (Input Teks / Autocomplete)
       ▼
[GET /api/terminology/search?q=demam&system=ICD-10]
       │
       ▼
[Terminology Engine] ──(Fast Lookup)──> [terminology_concepts]
       │
       │ (Cross-Reference Resolution)
       ▼
[terminology_mappings] ─────────────> [BPJS / SatuSehat Code]
```

### Data Model & Schema (Database Migrations)

```sql
-- 1. Katalog Sistem Kodifikasi / Standar Terminologi
CREATE TABLE IF NOT EXISTS terminology_code_systems (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NULL, -- NULL jika master global platform, ID jika khusus tenant
    code TEXT NOT NULL,     -- e.g. 'ICD-10', 'ICD-9-CM', 'LOINC', 'KFA', 'HS-CODE', 'KBLI'
    name TEXT NOT NULL,
    uri TEXT NULL,          -- e.g. 'http://hl7.org/fhir/sid/icd-10'
    version TEXT NULL,
    publisher TEXT NULL,
    description TEXT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    is_system INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_terminology_cs_tenant_code ON terminology_code_systems(tenant_id, code);

-- 2. Daftar Konsep & Kode Standar
CREATE TABLE IF NOT EXISTS terminology_concepts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NULL,
    code_system_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    display_name TEXT NOT NULL,
    description TEXT NULL,
    parent_code TEXT NULL,
    synonyms TEXT NULL,  -- JSON array teks sinonim pencarian
    properties TEXT NULL, -- JSON metadata atribut
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (code_system_id) REFERENCES terminology_code_systems(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_terminology_concepts_code ON terminology_concepts(tenant_id, code_system_id, code);
CREATE INDEX IF NOT EXISTS idx_terminology_concepts_name ON terminology_concepts(display_name);

-- 3. Pemetaan Lintas Sistem / Cross-Reference Mappings
CREATE TABLE IF NOT EXISTS terminology_mappings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    source_system_id INTEGER NOT NULL,
    source_code TEXT NOT NULL,
    target_system_id INTEGER NOT NULL,
    target_code TEXT NOT NULL,
    equivalence TEXT NOT NULL DEFAULT 'equivalent', -- 'equivalent', 'narrower', 'wider', 'related_to'
    comments TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (source_system_id) REFERENCES terminology_code_systems(id) ON DELETE CASCADE,
    FOREIGN KEY (target_system_id) REFERENCES terminology_code_systems(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_terminology_map_tenant_source ON terminology_mappings(tenant_id, source_system_id, source_code);
```

### Service Contract (`TerminologyRegistryInterface.php`)

```php
namespace App\Modules\Terminology\Contracts;

interface TerminologyRegistryInterface
{
    // Code Systems
    public function createCodeSystem(array $data): array;
    public function getCodeSystem(int $id): ?array;
    public function getCodeSystemByCode(string $code): ?array;
    public function listCodeSystems(array $filters = []): array;
    public function updateCodeSystem(int $id, array $data): array;
    public function deleteCodeSystem(int $id): bool;

    // Concepts Dictionary
    public function createConcept(array $data): array;
    public function getConcept(int $id): ?array;
    public function getConceptByCode(string $systemCode, string $conceptCode): ?array;
    public function searchConcepts(string $query, ?string $systemCode = null, int $limit = 20): array;
    public function listConcepts(int $codeSystemId, array $filters = []): array;
    public function updateConcept(int $id, array $data): array;
    public function deleteConcept(int $id): bool;

    // Cross-Reference Mappings
    public function createMapping(array $data): array;
    public function listMappings(array $filters = []): array;
    public function deleteMapping(int $id): bool;
    public function resolveMapping(string $sourceSystemCode, string $sourceCode, string $targetSystemCode): ?array;

    // Bulk Importer
    public function bulkImportConcepts(int $codeSystemId, array $concepts): int;
}
```

---

## 4. Edge Cases & Error Scenarios

- **Kasus 1: Pencarian Bahasa Awam / Sinonim**
  - Dokter atau staf mengetik kata umum dalam bahasa Indonesia (misal: "cacar air", "asma", "gagal ginjal"). Sistem mencocokkan field `synonyms` secara peka dan mengembalikan kode resmi internasional terkait (`B01.9 Varicella`, `J45 Asthma`, `N18 Chronic kidney disease`).
- **Kasus 2: Duplikasi Kode dalam Sistem Kodifikasi yang Sama**
  - Satu sistem kodifikasi tidak boleh memiliki kode ganda untuk tenant yang sama. Terjadi exception `InvalidArgumentException` jika kode sudah ada.
- **Kasus 3: Kode Target Tidak Ditemukan saat Translasi**
  - Jika `resolveMapping()` tidak menemukan pemetaan langsung, fungsi mengembalikan `null` dengan graceful tanpa menyebabkan fatal crash.
- **Kasus 4: Multi-Tenant vs Master Global Platform**
  - Sistem kodifikasi berstatus `tenant_id = NULL` (global) dapat dibaca oleh seluruh tenant, namun modifikasi data oleh tenant otomatis terisolasi pada `tenant_id` masing-masing.

---

## 5. Test Strategy

- **Unit Tests (`tests/Unit/TerminologyModuleTest.php`)**:
  - Test pendaftaran sistem kodifikasi standar (ICD-10, ICD-9-CM, KBLI, HS-Code).
  - Test pembuatan konsep, penambahan kata kunci sinonim, dan auto-complete search.
  - Test pencarian berdasarkan kode vs nama vs sinonim.
  - Test pemetaan silang (*cross-reference mapping*) antar sistem dan resolusi mapping.
  - Test bulk import ribuan konsep secara atomik.
  - Test isolasi tenant pada pemetaan khusus tenant.
- **Integration Tests**:
  - Simulasi resolusi diagnosis pada form pendaftaran atau rekam medis elektronik.

---

## 6. Atomic Task Checklist

- [x] **Phase 1: Setup Modul, Arsitektur, & Database Migrations**
  - [x] 1.1 Buat manifest modul `app/Modules/Terminology/module.json`.
  - [x] 1.2 Buat migrasi `database/migrations/2026_10_28_000029_create_terminology_and_code_systems_tables.sql`.
  - [x] 1.3 Jalankan migrasi via console runner dan verifikasi skema database.
- [x] **Phase 2: Service Contracts & Core Engine Implementation (TDD)**
  - [x] 2.1 Buat interface `App\Modules\Terminology\Contracts\TerminologyRegistryInterface`.
  - [x] 2.2 Buat Service Provider `App\Modules\Terminology\TerminologyServiceProvider` dan daftarkan modul.
  - [x] 2.3 Daftarkan helper global `terminology()` di `core/helpers.php`.
  - [x] 2.4 Tulis test suite `tests/Unit/TerminologyModuleTest.php` mencakup skenario lookup, sinonim, mapping, bulk import, dan tenant isolation.
  - [x] 2.5 Implementasikan `App\Modules\Terminology\Services\TerminologyService` hingga seluruh test lolos 100%.
- [x] **Phase 3: Web UI, Routes, & Programmatic REST API**
  - [x] 3.1 Buat routes modul di `app/Modules/Terminology/routes.php`.
  - [x] 3.2 Implementasikan Web Controller `TerminologyController` untuk antarmuka katalog kodifikasi dan mapping.
  - [x] 3.3 Implementasikan REST API Controller `ApiTerminologyController` untuk endpoint autocomplete pencarian cepat.
  - [x] 3.4 Buat antarmuka pengguna responsif Inertia React `resources/js/Pages/Terminology/Index.tsx` dengan Semantic UI Tokens.
- [x] **Phase 4: Final Verification, Review & Handoff**
  - [x] 4.1 Jalankan seluruh test suite global via `vendor/bin/phpunit.bat`.
  - [x] 4.2 Jalankan `npm run typecheck` dan build aset frontend.
  - [x] 4.3 Perbarui `specs/README.md` dan `.agents/SESSION_HANDOFF.md`.
  - [x] 4.4 Merge atomic commit ke branch `main`.
