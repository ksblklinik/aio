# Rule 04: Git Discipline & Atomic Commits

## 1. Kapan Harus Melakukan Commit?
- **Commit per Sub-Tugas Atomik**: Lakukan commit tepat setelah satu sub-tugas dalam checklist dokumen spec selesai diimplementasikan dan berhasil diverifikasi (test passing).
- **Jangan Menumpuk Perubahan Besar**: Hindari membuat satu commit raksasa di akhir pengerjaan. Riwayat commit harus mencerminkan progres bertahap dari dokumen spec.

---

## 2. Format Pesan Commit (Conventional Commits + Spec ID)
Format standar:
```
<type>(<scope>): <deskripsi singkat> (SPEC-<nomor>)
# atau: <type>(<scope>): <deskripsi singkat> [spec-<nomor>/task-<id>]
```

### Jenis `<type>` yang Digunakan:
- `feat`: Penambahan fitur fungsional atau modul baru.
- `test`: Penambahan atau perbaikan unit/integration test.
- `fix`: Perbaikan bug atau penanganan edge-case.
- `refactor`: Perubahan struktur kode tanpa mengubah fungsionalitas eksternal.
- `docs`: Pembuatan atau pembaruan dokumen spec / dokumentasi teknis.
- `chore`: Perubahan konfigurasi, tooling, atau dependensi build.

### Contoh Pesan Commit:
- `docs(spec): draft specification for universal invariants (SPEC-046)`
- `feat(core): expand universal invariants to 30 with 3 new reconciliation auditors (SPEC-048)`
- `test(auth): add unit test for password hashing [spec-001/task-2.1]`
- `feat(auth): implement bcrypt hashing service [spec-001/task-2.1]`

---

## 3. Aturan Keamanan & Integritas (Guardrails)

1. **Never Stage Everything (`git add .`) Buta**:
   Hanya stage file yang berkaitan langsung dengan sub-tugas yang diselesaikan.
2. **Anti Spec-Drift (Wajib Sinkron)**:
   Setiap kali file kode diubah, file spesifikasi terkait di `specs/` **wajib di-stage dalam commit yang sama** (`git add path/to/code specs/<nomor-spec>.md`) dengan status checkbox `[x]` yang sudah diperbarui.
3. **Cegah Bocornya Rahasia & Artefak**:
   Sebelum commit, periksa `git status` dan pastikan file `.env` (kecuali `.env.example`), credential, SQLite database testing temporer, atau file build tidak masuk ke staging area.
4. **Commit Hanya Setelah Verifikasi**:
   Jangan pernah melakukan commit jika unit test masih gagal atau build masih error. Jalankan `scripts/verify.ps1` sebelum commit.
5. **Mitigasi Dirty Working Tree**:
   Sebelum berpindah branch atau memulai spec baru, pastikan `git status` berstatus bersih (*working tree clean*). Simpan perubahan belum selesai via stash (`git stash`) jika mendesak.
6. **Path Separator di Windows**:
   Gunakan forward slash (`/`) pada argumen file Git (contoh: `git add src/index.ts specs/001-setup.md`) untuk konsistensi cross-platform.
