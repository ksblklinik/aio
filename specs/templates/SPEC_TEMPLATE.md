# SPEC-{{ID}}: {{Nama Fitur}}

- **Status**: `Draft` | `In Review` | `Approved` | `In Progress` | `Completed`
- **Owner / Author**: {{Nama}}
- **Tanggal Dibuat**: {{YYYY-MM-DD}}
- **Target File / Modul**: `path/to/module`

---

## 1. Problem Statement & Context
Jelaskan masalah yang ingin dipecahkan, motivasi bisnis/teknis, dan dampak jika fitur ini tidak dibuat.

## 2. Goals & Non-Goals
### Goals
- [ ] Fitur A mampu melakukan X dalam kondisi Y.
- [ ] Integrasi dengan komponen Z.

### Non-Goals
- Fitur ini TIDAK mencakup refactor sistem B.
- Fitur ini belum mencakup integrasi pihak ketiga C pada iterasi saat ini.

## 3. Technical Architecture & Design
### Alur Data / Komponen
```
[User / Client] ──> [Endpoint / Handler] ──> [Business Service] ──> [Database / Store]
```

### Data Model & Schema
Jelaskan perubahan tabel, model class, atau interface TypeScript/Go/Python:
```typescript
// Contoh interface atau skema
interface ExampleModel {
  id: string;
  name: string;
  createdAt: Date;
}
```

### API Endpoints / Function Signatures
- `POST /api/v1/example`: Input `{ name: string }`, Output `{ id: string, name: string }`

## 4. Edge Cases & Error Scenarios
- **Kasus 1**: Payload kosong atau invalid format $\rightarrow$ Return validation error 400.
- **Kasus 2**: Koneksi database terputus $\rightarrow$ Retry 3x atau throw graceful error.
- **Kasus 3**: Akses tidak sah $\rightarrow$ Return 401/403.

## 5. Test Strategy
- **Unit Test**: Uji fungsi parsing, kalkulasi bisnis, dan pemetaan skema.
- **Integration Test**: Uji endpoint dengan mock database atau database in-memory/test.
- **Manual Verification**: Periksa respons API via terminal/curl atau periksa UI browser.

---

## 6. Atomic Task Checklist
Pecah pengerjaan ke dalam langkah-langkah kecil (atomik) yang independen:

- [ ] **Phase 1: Setup & Data Contracts**
  - [ ] 1.1 Buat model/interface data dan type definitions.
  - [ ] 1.2 Buat skema migrasi database jika diperlukan.
- [ ] **Phase 2: Core Logic & Tests**
  - [ ] 2.1 Buat unit test kasus normal (*happy path*).
  - [ ] 2.2 Buat unit test kasus gagal (*edge cases*).
  - [ ] 2.3 Implementasikan fungsi bisnis hingga semua test lolos.
- [ ] **Phase 3: Integration & Endpoints**
  - [ ] 3.1 Buat route/endpoint controller.
  - [ ] 3.2 Sambungkan controller ke service logic.
  - [ ] 3.3 Uji integrasi endpoint.
- [ ] **Phase 4: Final Verification & Clean-up**
  - [ ] 4.1 Jalankan seluruh test suite (`npm test` / test runner terkait).
  - [ ] 4.2 Jalankan linter dan type-check.
  - [ ] 4.3 Verifikasi performa dan bersihkan log debug.
