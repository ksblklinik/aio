-- @up
CREATE TABLE IF NOT EXISTS tenant_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    module TEXT NOT NULL DEFAULT 'core',
    key TEXT NOT NULL,
    value TEXT NULL,
    type TEXT NOT NULL DEFAULT 'string',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(tenant_id, module, key)
);

CREATE INDEX IF NOT EXISTS idx_tenant_settings_lookup ON tenant_settings(tenant_id, module, key);

-- @down
DROP INDEX IF EXISTS idx_tenant_settings_lookup;
DROP TABLE IF EXISTS tenant_settings;
