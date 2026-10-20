# Rule 06: Proactive MCP Recommendation & Lifecycle

## 1. Prinsip Utama
Model Context Protocol (MCP) memungkinkan agen mengakses data eksternal (database nyata, browser testing, dokumentasi API terkini, dev tools) secara terstruktur.

Agen **wajib secara proaktif menganalisis dan merekomendasikan server MCP baru** kepada pengguna ketika mendeteksi adanya kebutuhan proyek yang akan jauh lebih akurat/efisien jika menggunakan MCP.

---

## 2. Kapan Agen Harus Merekomendasikan MCP Baru?

Agen harus memberikan rekomendasi MCP pada saat:

### A. Fase Perancangan Spec (Phase 1 & 2)
1. **Adopsi Database Baru**:
   - Jika spec melibatkan PostgreSQL, MySQL, Redis, atau Supabase $\rightarrow$ Rekomendasikan MCP Database terkait (misal: `@modelcontextprotocol/server-postgres`).
   - *Manfaat bagi pengguna*: Agen dapat langsung memeriksa relasi tabel, foreign keys, dan tipe kolom nyata tanpa menebak-nebak skema.
2. **Kebutuhan UI E2E / Visual Verification**:
   - Jika spec membangun antarmuka web yang kompleks $\rightarrow$ Rekomendasikan MCP Browser (misal: Playwright atau Puppeteer MCP).
   - *Manfaat bagi pengguna*: Agen dapat memverifikasi rendering UI dan mengambil screenshot pengujian otomatis.
3. **Integrasi Eksternal / API Pihak Ketiga**:
   - Jika spec menghubungkan sistem dengan layanan eksternal (GitHub, OpenAPI, Stripe, dll.) $\rightarrow$ Rekomendasikan MCP resmi layanan tersebut atau MCP Fetch/OpenAPI.

### B. Fase Investigasi / Debugging (Phase 4)
- Jika agen kesulitan melacak error yang berkaitan dengan state database atau log sistem terpusat $\rightarrow$ Usulkan penambahan MCP untuk mengakses log/state tersebut secara langsung.

---

## 3. Format Rekomendasi kepada Pengguna
Ketika merekomendasikan MCP baru, agen harus menyajikan:
1. **Nama & Fungsi MCP**: Layanan apa yang disediakan dan mengapa proyek ini membutuhkannya sekarang.
2. **Keuntungan Nyata**: Perbandingan jika menggunakan MCP vs menggunakan perintah CLI manual.
3. **Snippet Konfigurasi**: Blok JSON siap pakai yang akan ditambahkan ke workspace plugin di `.agents/plugins/jstudio/mcp_config.json` (atau global `~/.gemini/config/mcp_config.json`).
4. **Persetujuan Pengguna**: Meminta persetujuan sebelum menambahkan konfigurasi ke file MCP terkait.

---

## 4. Guardrail: Graceful Fallback
- MCP adalah peningkat kapabilitas (*enhancer*), bukan penghalang (*blocker*).
- Jika pengguna memilih **tidak** mengaktifkan MCP yang direkomendasikan, agen **wajib tetap dapat bekerja** menggunakan alat bawaan standar (file skema lokal, CLI biasa, atau unit test mock) tanpa mogok.
