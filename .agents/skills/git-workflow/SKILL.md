---
name: git-workflow
description: Mengelola version control Git secara teratur dengan atomic commits berbasis spec tasks, branching strategy yang rapi, dan pencegahan kebocoran data sensitif.
---

# Skill: Git Workflow & Atomic Commits

Gunakan skill ini untuk mengelola riwayat Git yang disiplin, membuat branch fitur, dan melakukan atomic commit setelah setiap sub-tugas spec diverifikasi.

## Alur Kerja

### 1. Inisialisasi Fitur Baru (Branching)
Sebelum mulai mengerjakan spec baru:
1. Pastikan repo Git sudah terinisialisasi dan bersih (`git status`).
2. **Branch Switch Pre-Flight**: Dilarang berpindah branch jika working tree kotor. Stash atau commit perubahan sebelum `checkout`.
3. Buat branch baru berbasis nomor spec dan nama fitur:
   ```bash
   git checkout -b feat/<nomor-spec>-<nama-fitur>
   # Contoh: git checkout -b feat/001-user-auth
   ```

### 2. Melakukan Commit Atomik untuk Sub-Tugas Spec
Segera setelah sub-tugas di dokumen spec diverifikasi lolos:
1. Periksa file yang termodifikasi:
   ```bash
   git status
   ```
2. Stage **hanya** file yang relevan dengan sub-tugas tersebut:
   ```bash
   git add <path/to/test-file> <path/to/code-file> specs/<nomor-spec>-<nama-fitur>.md
   ```
3. Buat commit menggunakan konvensi:
   ```bash
   git commit -m "<type>(<scope>): <deskripsi singkat> (SPEC-<nomor>)"
   # atau per task: git commit -m "<type>(<scope>): <deskripsi singkat> [spec-<nomor>/task-<id>]"
   ```
4. Pastikan file `.env` atau credential tidak pernah masuk ke stage.

### 3. Menyelesaikan Spec & Mempersiapkan Review
Setelah seluruh sub-tugas di spec selesai (`[x]` semua):
1. Periksa seluruh riwayat commit di branch tersebut:
   ```bash
   git log --oneline -n 10
   ```
2. Pastikan working directory bersih (`git status` menampilkan *nothing to commit, working tree clean*).
3. Jika pengguna ingin merge ke `main`:
   ```bash
   git checkout main
   git merge --no-ff feat/<nomor-spec>-<nama-fitur> -m "feat: complete spec-<nomor> <nama-fitur>"
   ```

### 4. Prosedur Rollback / Batal Tugas (Task Abort)
Jika di tengah pengerjaan sub-tugas terjadi kegagalan fatal, regresi berat, atau pengguna meminta membatalkan sub-tugas:
1. **Batalkan Staging & Perubahan Lokal**:
   ```bash
   # Hapus file dari staging area jika sudah ter-stage
   git restore --staged .
   # Kembalikan semua file yang dimodifikasi ke commit terakhir yang aman
   git restore .
   # Hapus file baru / untracked yang dibuat selama sub-tugas yang gagal
   git clean -fd
   ```
2. **Kembalikan Status Spec**:
   - Pastikan checkbox tugas di spec target tetap berstatus belum selesai (`- [ ]`).
   - Catat kendala pada log/chat dan rumuskan kembali pendekatan yang benar sebelum mencoba lagi.
