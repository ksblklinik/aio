# SPEC-017: Core Enterprise Data, AI Gateway, and Interoperability Engine

- **Status**: `In Progress`
- **Owner / Author**: Antigravity & Lead Architect
- **Tanggal Dibuat**: 2026-10-27
- **Target File / Modul**: `core/Database`, `core/Http`, `core/Vector`, `core/Graph`, `core/Ai`, `core/helpers.php`

---

## 1. Problem Statement & Context
Aplikasi SaaS modern skala enterprise memerlukan kapabilitas fondasi yang melampaui CRUD dasar:
1. **Multi-Database**: Modul tertentu memerlukan isolasi atau koneksi ke database sekunder (misal analitik, legacy ERP, PostgreSQL / MySQL), bukan hanya default single SQLite.
2. **Interoperabilitas Real-Time (SSE & Streaming CSV)**: Komunikasi satu arah real-time dari server ke browser untuk log background worker, notifikasi instan, dan streaming output LLM membutuhkan Server-Sent Events (SSE) native tanpa WebSocket server yang rumit. Selain itu, ekspor/impor ribuan baris data membutuhkan generator streaming memory-safe.
3. **Pencarian Semantik & AI Engine**: Modul modern (Knowledge Base, RAG Chatbot, Rekomendasi Produk, Otomatisasi Dokumen) membutuhkan representasi vector embeddings, cosine similarity search, serta gateway LLM terpadu dengan ekstraksi JSON terstruktur dan streaming token.
4. **Graph & Hierarchy Traversal**: Modul rantai pasok (BOM), bagan organisasi perusahaan, dan approval hierarchy membutuhkan penelusuran graf dan pohon (adjacency list / transitive traversal) bebas siklus (*cycle-free*).

Membangun kapabilitas ini di dalam modul bisnis melanggar **Rule 08 (Zero Infrastructure Leakage)**. Oleh karena itu, seluruh fondasi ini harus disediakan oleh Core Platform.

---

## 2. Goals & Non-Goals

### Goals
- [x] Menyediakan **DatabaseManager** untuk multi-connection dinamis dengan fallback default ke SQLite aktif.
- [x] Menambahkan query utilities pada `TenantScope`: multi-column search sanitization (`search()`) dan memory-safe chunking (`chunk()`).
- [x] Menyediakan **Server-Sent Events (SSE) Engine** pada `Response` (`Response::sse(callable)`) dan streaming CSV exporter/importer (`csv_download()`, `csv_parse()`).
- [x] Menyediakan **VectorStore Engine** berbasis SQLite BLOB + pure PHP cosine similarity (offline-first, zero dependency) dengan skema `vector_embeddings`.
- [x] Menyediakan **GraphHelper** untuk penelusuran struktur hierarki, pohon, dan pendeteksian siklus (*cycle detection*).
- [x] Menyediakan **Unified AI Gateway (`AiManager`)** dengan interface standar (`chat`, `stream`, `embed`, `extractJson`) dan driver `MockAiDriver`, `OpenAiDriver`, serta `GeminiDriver`.
- [x] Menyediakan helper global baru di `core/helpers.php`: `ai()`, `vector_store()`, `csv_download()`, dan `csv_parse()`.
- [x] 100% test coverage via PHPUnit offline-first tanpa external API hit.

### Non-Goals
- Tidak memasang library vendor eksternal yang berat (seperti SDK Python/Node atau binary extension C pgvector wajib); seluruh implementasi murni PHP 8.3 & SQLite native dengan adapter fleksibel jika backend enterprise aktif.

---

## 3. Technical Architecture & Design

### Data Flow
```
[Business Module / Client]
         │
         ├──> db(?string $table, ?string $connection) ──> [DatabaseManager] ──> [PDO Connections]
         ├──> Response::sse(callable) / csv_download() ──> [Streaming Engine] ──> [Client Browser]
         ├──> vector_store() ──> [VectorStoreManager] ──> [SqliteVectorDriver] ──> [vector_embeddings]
         ├──> GraphHelper ──> [Adjacency Tree Traversal & Cycle Detection]
         └──> ai() ──> [AiManager] ──> [MockAiDriver / OpenAiDriver / GeminiDriver]
```

### Data Schema
Tabel migrasi baru: `database/migrations/2026_10_27_000015_create_vector_embeddings_table.sql`:
```sql
-- @up
CREATE TABLE IF NOT EXISTS vector_embeddings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    collection TEXT NOT NULL,
    item_id TEXT NOT NULL,
    embedding BLOB NOT NULL,
    dimensions INTEGER NOT NULL,
    metadata TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(tenant_id, collection, item_id)
);
CREATE INDEX IF NOT EXISTS idx_vectors_tenant_col ON vector_embeddings(tenant_id, collection);

-- @down
DROP INDEX IF EXISTS idx_vectors_tenant_col;
DROP TABLE IF EXISTS vector_embeddings;
```

---

## 4. Edge Cases & Error Scenarios
- **Vector Dimension Mismatch**: Ketika dimensi query vector berbeda dengan dimensi embedding di database, lemparkan `InvalidArgumentException` terstruktur.
- **SSE Stream Client Disconnect**: Tangani pemutusan koneksi klien (`connection_aborted()`) dengan graceful termination tanpa unhandled fatal error.
- **Large Dataset Memory Leak**: Gunakan PHP Generators (`yield`) pada streaming CSV dan memory buffering terkontrol.
- **Graph Infinite Loop**: Deteksi cycle pada graf pohon berulang menggunakan `visited` hash map untuk mencegah loop rekursif tak terhingga.
- **AI API Failover / Offline**: Sediakan `MockAiDriver` aktif secara default jika API Key tidak disetel di environment, menjamin test suite dan development lokal tetap 100% berjalan offline.

---

## 5. Test Strategy
- **DatabaseManagerTest**: Menguji registrasi dan resolusi multi-koneksi PDO.
- **TenantScopeUtilitiesTest**: Menguji `$scope->search()` dan `$scope->chunk()` dengan multi-tenant isolation.
- **StreamAndSseTest**: Menguji framing format SSE (`data: ...\n\n`) dan output streaming CSV.
- **VectorStoreTest**: Menguji penyimpanan embedding, kalkulasi cosine similarity, dan retrieval similarity search.
- **GraphHelperTest**: Menguji `getDescendants`, `getTree`, dan deteksi siklus relasi.
- **AiGatewayTest**: Menguji chat completion, streaming callback, text embedding, dan JSON schema extraction dengan `MockAiDriver`.

---

## 6. Atomic Task Checklist

- [ ] **Phase 1: DatabaseManager & TenantScope Query Utilities**
  - [ ] 1.1 Buat `Core\Database\DatabaseManager` untuk mengelola multi-koneksi database dari `config('database')`.
  - [ ] 1.2 Perbarui `TenantScope` dengan method `search(array $columns, string $term)` dan `chunk(int $count, callable $callback)`.
  - [ ] 1.3 Perbarui helper `db(?string $table = null, ?string $connection = null)`.

- [ ] **Phase 2: High-Performance Interoperability (SSE & CSV Stream)**
  - [ ] 2.1 Tambahkan method `Response::stream()` dan `Response::sse()` pada `Core\Http\Response`.
  - [ ] 2.2 Buat `Core\Http\CsvStream` dengan method `download()` dan `parse()`.
  - [ ] 2.3 Tambahkan helper global `csv_download()` dan `csv_parse()` di `core/helpers.php`.

- [ ] **Phase 3: Vector Store Engine & Semantic Search**
  - [ ] 3.1 Buat migrasi `2026_10_27_000015_create_vector_embeddings_table.sql`.
  - [ ] 3.2 Buat `Core\Vector\VectorStoreInterface` dan implementasi `Core\Vector\SqliteVectorDriver` dengan fungsi kalkulasi Cosine Similarity.
  - [ ] 3.3 Buat `Core\Vector\VectorManager` dan helper global `vector_store(?string $driver = null)`.

- [ ] **Phase 4: Graph & Tree Traversal Engine**
  - [ ] 4.1 Buat `Core\Graph\GraphHelper` untuk adjacency list traversal, ancestors/descendants retrieval, tree nesting, dan cycle detection.

- [ ] **Phase 5: Universal AI Gateway & LLM Orchestration**
  - [ ] 5.1 Buat `config/ai.php` dan update `.env.example` / `.env`.
  - [ ] 5.2 Buat interface `Core\Ai\AiClientInterface`.
  - [ ] 5.3 Buat `MockAiDriver`, `OpenAiDriver`, dan `GeminiDriver` (native cURL/stream tanpa external SDK).
  - [ ] 5.4 Buat `Core\Ai\AiManager` dan helper global `ai(?string $driver = null)`.
  - [ ] 5.5 Daftarkan singleton AI dan Vector di `core/Foundation/Application.php`.

- [ ] **Phase 6: Comprehensive TDD, Self-Verification & Integration**
  - [ ] 6.1 Buat test suite lengkap di `tests/Unit/CoreEnterpriseAiAndInteropTest.php`.
  - [ ] 6.2 Jalankan seluruh PHPUnit suite (target: 100% PASS).
  - [ ] 6.3 Jalankan `./scripts/verify.ps1`, update status spec, dan sinkronkan `SESSION_HANDOFF.md`.
