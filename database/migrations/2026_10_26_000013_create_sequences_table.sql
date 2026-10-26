-- @up
CREATE TABLE IF NOT EXISTS sequences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    prefix TEXT NOT NULL DEFAULT '',
    current_value INTEGER NOT NULL DEFAULT 0,
    padding INTEGER NOT NULL DEFAULT 5,
    reset_period TEXT NOT NULL DEFAULT 'never',
    last_reset_year INTEGER NULL,
    last_reset_month INTEGER NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(tenant_id, name)
);

CREATE INDEX IF NOT EXISTS idx_sequences_tenant_name ON sequences(tenant_id, name);

-- @down
DROP INDEX IF EXISTS idx_sequences_tenant_name;
DROP TABLE IF EXISTS sequences;
