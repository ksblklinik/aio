# aio

Repositori pengembangan perangkat lunak berbasis **Spec-Driven Development** untuk solo / independent full-stack developer dengan integrasi native **Antigravity IDE**.

---

## ðŸš€ Memulai (Quick Start)

Untuk menyiapkan environment lokal, file `.env`, dependensi, dan mengaktifkan Git hook otomatis, jalankan satu perintah:

```powershell
powershell -ExecutionPolicy Bypass -File ./scripts/dev.ps1
```

Opsi parameter:
* `powershell -ExecutionPolicy Bypass -File ./scripts/dev.ps1 -SetupOnly` : Hanya bootstrap env dan dependensi tanpa menyalakan dev server.
* `powershell -ExecutionPolicy Bypass -File ./scripts/dev.ps1 -CheckOnly` : Periksa integritas sistem dan status hook.

---

## ðŸ›ï¸ Alur Kerja Rekayasa (4-Phase Protocol)

Proyek ini dipandu oleh konstitusi [AGENTS.md](file:///c:/aplikasi/jstudio/AGENTS.md):

```
[Phase 1: Clarify & Grill] â”€â”€> [Phase 2: Spec & Plan] â”€â”€> [Phase 3: Atomic TDD] â”€â”€> [Phase 4: Verify & Review]
```

1. **Perancangan Fitur**: Gunakan skill [`spec-builder`](file:///c:/aplikasi/jstudio/.agents/skills/spec-builder/SKILL.md) untuk membuat dokumen spesifikasi baru di `specs/<nomor>-<nama>.md`.
2. **Eksekusi Atomik**: Gunakan skill [`spec-implement`](file:///c:/aplikasi/jstudio/.agents/skills/spec-implement/SKILL.md) untuk menyelesaikan sub-tugas satu per satu.
3. **Pencegahan Regression**: Setiap commit wajib diverifikasi oleh gerbang otomatis [scripts/verify.ps1](file:///c:/aplikasi/jstudio/scripts/verify.ps1).
4. **Git Pre-Commit Hook**: Dipasang secara otomatis via `.githooks/pre-commit` untuk memblokir commit jika test gagal atau ada secret yang bocor.

---

## ðŸ“ Struktur Direktori

```
jstudio/
â”œâ”€â”€ AGENTS.md                          # Master protocol & constitution
â”œâ”€â”€ README.md                          # Dokumentasi panduan onboarding ini
â”œâ”€â”€ CHANGELOG.md                       # Riwayat rilis & perubahan
â”œâ”€â”€ .gitignore / .gitattributes        # Aturan Git & standardisasi LF
â”œâ”€â”€ .env.example                       # Template konfigurasi environment
â”œâ”€â”€ .githooks/pre-commit               # Git pre-commit hook otomatis
â”œâ”€â”€ .agents/
â”‚   â”œâ”€â”€ rules/ (01 to 06)              # Aturan baku (TDD, Token, Git, MCP)
â”‚   â”œâ”€â”€ skills/ (7 skills)             # Skill operasional agen Antigravity
â”‚   â””â”€â”€ plugins/jstudio/               # Plugin MCP & tools proyek
â”œâ”€â”€ scripts/
â”‚   â”œâ”€â”€ dev.ps1                        # Script bootstrap dev satu perintah
â”‚   â”œâ”€â”€ verify.ps1                     # Gerbang verifikasi 4-in-1
â”‚   â””â”€â”€ map.ps1                        # Generator peta simbol ala Aider
â””â”€â”€ specs/
    â”œâ”€â”€ README.md                      # Indeks seluruh spesifikasi aktif
    â”œâ”€â”€ 000-governance-bootstrap.md    # Spec bootstrap tata kelola
    â””â”€â”€ templates/SPEC_TEMPLATE.md     # Template spesifikasi standar
```

---

## ðŸ›¡ï¸ Gerbang Keamanan & Verifikasi
Sebelum commit atau push, Anda atau agen dapat memvalidasi seluruh repo kapan saja:
```powershell
powershell -ExecutionPolicy Bypass -File ./scripts/verify.ps1
```
Pemeriksaan mencakup:
1. Pemindaian kebocoran file `.env` dan token rahasia (*hardcoded secrets regex*).
2. Pemeriksaan sinkronisasi dokumen spesifikasi (*anti spec-drift*).
3. Eksekusi rangkaian automated test dan typecheck.
4. Inspeksi status Git working tree.

