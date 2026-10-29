# Spec-Driven Development Protocol

Dokumen ini mendefinisikan aturan kerja (constitution) standar untuk pengembangan di repositori ini. Semua AI agent dan kontributor wajib mengikuti disiplin rekayasa berikut untuk mencegah *vibe coding* serampangan.

---

## 4 Fase Alur Kerja (Workflow Phases)

```
[Phase 1: Clarify & Grill] ──> [Phase 2: Spec & Plan] ──> [Phase 3: Atomic TDD] ──> [Phase 4: Verify & Review]
```

### 1. Phase 1: Clarify & Grill (Penajaman)
- Jangan langsung menulis kode untuk fitur atau perubahan non-trivial.
- Uji asumsi, identifikasi batasan (*constraints*), penanganan error (*edge cases*), dan dependensi.
- Jika ada ambiguitas, tanyakan secara langsung atau gunakan rekomendasi slash command `/grill-me`.

### 2. Phase 2: Spec & Plan (Spesifikasi Teknis)
- Setiap fitur/perubahan signifikan harus memiliki dokumen spec di folder [`specs/`](file:///c:/aplikasi/aio/specs).
- Gunakan format dari [`specs/templates/SPEC_TEMPLATE.md`](file:///c:/aplikasi/aio/specs/templates/SPEC_TEMPLATE.md).
- Dokumen spec harus memuat daftar tugas atomik (*Atomic Task Checklist*) bertingkat `[ ]` yang dapat dilacak progresnya.

### 3. Phase 3: Atomic TDD & Commit (Eksekusi Bertahap)
- Kerjakan satu sub-tugas atomik dalam satu waktu di branch fitur (`feat/<nomor-spec>-<nama>`).
- Terapkan pendekatan Test-Driven Development (TDD) jika memungkinkan: tulis tes unit/integrasi terlebih dahulu atau beriringan.
- Perbarui status checkbox di file spec (`[ ]` menjadi `[x]`) segera setelah sub-tugas selesai dan diverifikasi.
- Buat **atomic commit Git** tepat setelah sub-tugas diverifikasi sesuai format Conventional Commits ([`04-git-discipline.md`](file:///c:/aplikasi/aio/.agents/rules/04-git-discipline.md)).

### 4. Phase 4: Self-Verify & Review (Verifikasi Mandiri)
- Lakukan verifikasi via terminal (eksekusi seluruh test suite, type-check, linter, build).
- Lakukan inspeksi mandiri terhadap kode yang diubah: pastikan tidak ada kode mati, tidak ada console log debugging liar, dan tidak merusak fungsionalitas lain (*no regression*).
- Pastikan working tree Git bersih dan siapkan riwayat commit untuk review atau merge.

---

## Aturan Tambahan (Guardrails)
1. **No Silent Assumptions**: Jika ada keputusan arsitektur krusial (pemilihan DB, format auth, pola state), mintalah persetujuan pengguna terlebih dahulu.
2. **Minimal & Clean Diffs**: Hanya modifikasi baris atau file yang benar-benar relevan dengan sub-tugas yang sedang dikerjakan.
3. **Git Hygiene**: Jangan pernah menggunakan `git add .` secara buta. Cegah commit file `.env`, credential, atau build artifacts.
4. **Dokumentasi Selalu Sinkron**: Jika ada perubahan arah di tengah pengerjaan, perbarui dokumen spec di `specs/` terlebih dahulu sebelum melanjutkan kode.
5. **Session Continuity & Handoff**:
   - **Saat Mengakhiri Sesi**: Agen wajib memperbarui [`.agents/SESSION_HANDOFF.md`](file:///c:/aplikasi/aio/.agents/SESSION_HANDOFF.md) yang mencatat branch aktif, status spec/checklist, dan langkah langsung berikutnya sebelum sesi ditutup.
   - **Saat Memulai Sesi Baru**: Agen wajib membaca `.agents/SESSION_HANDOFF.md` terlebih dahulu untuk memulihkan konteks kerja tanpa perlu penjelasan ulang dari pengguna.
6. **Zero Infrastructure Leakage**: Dilarang menambal atau membuat kapabilitas platform/infrastruktur (event bus, queue worker, auth wrapper) di level modul bisnis. Jika ada fondasi yang belum lengkap, agen wajib menghentikan pengerjaan kode bisnis, melaporkan celah fondasi, dan membuat spec penguatan Core terlebih dahulu.

---

## Indeks Aturan Kerja (.agents/rules/)
Setiap agen dan kontributor wajib mematuhi seluruh aturan berikut:
1. [01-spec-driven.md](file:///c:/aplikasi/aio/.agents/rules/01-spec-driven.md) — Tata cara penyusunan spec dan checklist atomik.
2. [02-code-quality-tdd.md](file:///c:/aplikasi/aio/.agents/rules/02-code-quality-tdd.md) — Kualitas kode, offline-first unit tests, dan mocking ketat.
3. [03-verification.md](file:///c:/aplikasi/aio/.agents/rules/03-verification.md) — Prosedur verifikasi mandiri sebelum commit.
4. [04-git-discipline.md](file:///c:/aplikasi/aio/.agents/rules/04-git-discipline.md) — Conventional Commits, anti spec-drift, dan branch per spec.
5. [05-context-token-efficiency.md](file:///c:/aplikasi/aio/.agents/rules/05-context-token-efficiency.md) — Efisiensi token, surgical reading/diffs, repo-map, dan No-Fluff brevity (Caveman).
6. [06-mcp-lifecycle-recommendation.md](file:///c:/aplikasi/aio/.agents/rules/06-mcp-lifecycle-recommendation.md) — Rekomendasi proaktif server MCP dan tata kelola plugin.
7. [07-semantic-ui-tokens.md](file:///c:/aplikasi/aio/.agents/rules/07-semantic-ui-tokens.md) — Disiplin semantic tokens dan integritas tema dark/light mode.
8. [08-zero-infrastructure-leakage.md](file:///c:/aplikasi/aio/.agents/rules/08-zero-infrastructure-leakage.md) — Pencegahan kebocoran infrastruktur dan larangan tambal lokal di modul bisnis.
9. [09-ponytail-pragmatism.md](file:///c:/aplikasi/aio/.agents/rules/09-ponytail-pragmatism.md) — Pragmatisme Ponytail, 7-Step Decision Ladder, anti-bloatware, dan minimalis diffs.
