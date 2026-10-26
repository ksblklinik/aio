-- @up
CREATE TABLE IF NOT EXISTS files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    user_id INTEGER NULL,
    disk TEXT NOT NULL DEFAULT 'local',
    original_name TEXT NOT NULL,
    file_name TEXT NOT NULL,
    file_path TEXT NOT NULL,
    mime_type TEXT NOT NULL,
    file_size INTEGER NOT NULL,
    checksum_sha256 TEXT NOT NULL,
    is_public INTEGER NOT NULL DEFAULT 0,
    attachable_type TEXT NULL,
    attachable_id INTEGER NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_files_tenant ON files(tenant_id);
CREATE INDEX IF NOT EXISTS idx_files_attachable ON files(attachable_type, attachable_id);

-- @down
DROP INDEX IF EXISTS idx_files_attachable;
DROP INDEX IF EXISTS idx_files_tenant;
DROP TABLE IF EXISTS files;
