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
