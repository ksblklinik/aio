# SPEC-019: Lightweight Workflow Engine and Transition Guard

- **Status**: `Completed`
- **Owner / Author**: Core Architecture Team
- **Tanggal Dibuat**: 2026-10-27
- **Target File / Modul**: `core/Workflow/`, `database/migrations/`, `core/Foundation/Application.php`, `core/helpers.php`

---

## 1. Problem Statement & Context
Aplikasi web enterprise, organisasi/yayasan, ERP, pengadaan (procurement), dan operasional lapangan (canvassing/fleet) memiliki siklus hidup entitas data yang harus patuh terhadap alur status (*status transitions*). Contoh:
- SK Pengurus / Notulen Rapat: `Draft` ➔ `Review` ➔ `Disahkan` ➔ `Diarsipkan`.
- Pesanan / Distribusi: `Pending` ➔ `Paid` ➔ `Processing` ➔ `Shipped` ➔ `Delivered`.
- Reimbursement / Anggaran: `Submitted` ➔ `Manager Approved` ➔ `Finance Paid`.

Tanpa Workflow Engine terpadu di Core, modul-modul bisnis terpaksa menulis validasi transisi status secara manual via `if-else` berserakan di controller atau service bisnis (*spaghetti code*). Hal ini menimbulkan risiko:
1. Status ilegal (transisi status yang melompati aturan bisnis sah).
2. Otorisasi longgar (peran yang tidak berhak dapat mengubah status entitas).
3. Hilangnya jejak audit riwayat perubahan status (siapa yang mengubah, kapan, dan apa alasannya/catatannya).

## 2. Goals & Non-Goals
### Goals
- [x] Menyediakan `WorkflowDefinition` yang fleksibel & deklaratif (states, initial state, transitions, guards).
- [x] Menyediakan `WorkflowManager` untuk pendaftaran workflow entitas, evaluasi validitas transisi (`canTransition`), eksekusi transisi dengan guards (`transition`), dan pencatatan audit trail otomatis.
- [x] Skema database `workflow_transitions` untuk rekam jejak audit perubahan status berjenjang terisolasi per tenant.
- [x] Integrasi otomatis ke `event_dispatch()` saat transisi berhasil (`workflow.{$entityType}.transitioned` dan `workflow.transitioned`).
- [x] Global helper `workflow(?string $entityType = null)` dan binding lazy singleton di container.
- [x] Zero-bloat mitigasi: Hanya berjalan on-demand saat dipanggil modul bisnis (0ms overhead pada modul non-workflow).

### Non-Goals
- Tidak membuat Visual BPMN / Drag-and-Drop Workflow Canvas (merupakan ranah UI / frontend modular, bukan core backend).
- Tidak mengotomatisasi timer timeout otomatis berbasis asynchronous worker (eksekusi asinkron tetap mendelegasikan ke `Queue` dan `ScheduleRunner` yang sudah ada).

## 3. Technical Architecture & Design
### Alur Komponen
```
[Business Service / Controller]
              │
              ▼
    workflow('surat_sk')->transition($entityId, $from, $to, $actorId, $notes)
              │
   ┌──────────┴──────────┐
   ▼                     ▼
[Guard Evaluation]  [Invalid Transition?]
   │                     │
   ├──> Pass             └──> Throw WorkflowException (HTTP 422 / 403)
   ▼
[Update Database Model]
   ▼
[Record Audit to workflow_transitions]
   ▼
[Dispatch Event: workflow.{entity}.transitioned]
```

### Data Model & Schema
Tabel: `workflow_transitions`
- `id` (INTEGER PRIMARY KEY AUTOINCREMENT)
- `tenant_id` (INTEGER NOT NULL)
- `entity_type` (VARCHAR(64) NOT NULL, e.g. `'surat_sk'`, `'order'`, `'procurement'`)
- `entity_id` (VARCHAR(64) NOT NULL)
- `from_status` (VARCHAR(64) NOT NULL)
- `to_status` (VARCHAR(64) NOT NULL)
- `transition_name` (VARCHAR(64) NULL)
- `actor_id` (INTEGER NULL)
- `actor_name` (VARCHAR(255) NULL)
- `notes` (TEXT NULL)
- `metadata` (TEXT NULL - JSON payload)
- `created_at` (DATETIME DEFAULT CURRENT_TIMESTAMP)

### Function Signatures
- `WorkflowManager::define(string $entityType, array $definition): WorkflowDefinition`
- `WorkflowManager::can(string $entityType, string $fromStatus, string $toStatus, ?object $actor = null, mixed $entity = null): bool`
- `WorkflowManager::transition(string $entityType, int|string $entityId, string $fromStatus, string $toStatus, ?int $actorId = null, ?string $actorName = null, ?string $notes = null, mixed $entity = null, array $metadata = []): array`
- `WorkflowManager::getHistory(string $entityType, int|string $entityId, ?int $tenantId = null): array`
- Helper: `workflow(?string $entityType = null)`

---

## 4. Edge Cases & Error Scenarios
- **Kasus 1: Transisi Status Ilegal**: Entitas mencoba pindah ke status yang tidak didefinisikan dalam aturan transition (misal: `draft` langsung loncat ke `approved`) $\rightarrow$ Throw `WorkflowException` ("Transisi dari [draft] ke [approved] tidak diizinkan").
- **Kasus 2: Guard / Permission Ditolak**: User tidak memiliki permission/role yang diwajibkan oleh guard $\rightarrow$ Throw `WorkflowException` ("Akses ditolak: Guard [surat.approve] gagal").
- **Kasus 3: Riwayat Audit Terisolasi Multi-Tenant**: Tenant A tidak boleh dapat melihat riwayat transisi workflow Tenant B $\rightarrow$ Strict tenant isolation query via `tenant_id`.

---

## 5. Test Strategy
- **Unit Test**: `tests/Unit/CoreWorkflowEngineTest.php`
  - Validasi inisialisasi workflow deklaratif & states.
  - Validasi evaluasi transisi legal dan pencegahan transisi ilegal.
  - Validasi eksekusi guards (callable closure dan string gate permission).
  - Validasi pencatatan tabel audit log `workflow_transitions`.
  - Validasi dispatching domain event saat transisi sukses.
  - Validasi isolasi tenant pada riwayat audit transisi.

---

## 6. Atomic Task Checklist

- [x] **Phase 1: Database Migration & Core Contracts**
  - [x] 1.1 Buat migrasi `database/migrations/2026_10_27_000017_create_workflow_transitions_table.sql`.
  - [x] 1.2 Jalankan migrasi via CLI pada `database.sqlite`.
  - [x] 1.3 Buat exception class `Core\Workflow\WorkflowException`.
- [x] **Phase 2: Workflow Definition & Manager Logic**
  - [x] 2.1 Buat `Core\Workflow\WorkflowDefinition` (states, transitions, guards).
  - [x] 2.2 Buat `Core\Workflow\WorkflowManager` (`define`, `can`, `transition`, `getHistory`).
  - [x] 2.3 Daftarkan lazy singleton di `core/Foundation/Application.php` dan global helper `workflow()` di `core/helpers.php`.
- [x] **Phase 3: Unit Testing & TDD Verification**
  - [x] 3.1 Buat `tests/Unit/CoreWorkflowEngineTest.php`.
  - [x] 3.2 Uji happy path: transisi legal, pencatatan audit log, dan event dispatching.
  - [x] 3.3 Uji edge cases: transisi ilegal ditolak, guard failure, dan tenant isolation.
- [x] **Phase 4: Verification & Git Integration**
  - [x] 4.1 Jalankan `pwsh -File ./scripts/verify.ps1`.
  - [x] 4.2 Lakukan atomic commit di branch `feat/019-lightweight-workflow-engine`.
  - [x] 4.3 Merge ke `main` dengan `--no-ff`.
  - [x] 4.4 Perbarui `specs/README.md` dan `.agents/SESSION_HANDOFF.md`.
