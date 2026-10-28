-- Migration: Create Outbox Events Table
-- Description: Stores transactional domain events atomically alongside business mutations to prevent dual-write anomalies.

CREATE TABLE IF NOT EXISTS outbox_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NULL,
    event_id TEXT NOT NULL UNIQUE,
    event_name TEXT NOT NULL,
    payload TEXT NOT NULL,
    target_channel TEXT NULL,
    status TEXT NOT NULL DEFAULT 'pending', -- 'pending', 'published', 'failed'
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 5,
    last_error TEXT NULL,
    published_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_outbox_status_created ON outbox_events(status, created_at);
CREATE INDEX IF NOT EXISTS idx_outbox_tenant_status ON outbox_events(tenant_id, status);
