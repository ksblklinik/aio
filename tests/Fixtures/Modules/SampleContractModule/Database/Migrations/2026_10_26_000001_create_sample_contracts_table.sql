-- @up
CREATE TABLE IF NOT EXISTS sample_contracts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- @down
DROP TABLE IF EXISTS sample_contracts;
