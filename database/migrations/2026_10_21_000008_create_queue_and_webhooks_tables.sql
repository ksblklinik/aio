-- Migration: 2026_10_21_000008_create_queue_and_webhooks_tables

CREATE TABLE IF NOT EXISTS jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NULL,
    queue TEXT NOT NULL DEFAULT 'default',
    payload TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    reserved_at TIMESTAMP NULL,
    available_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_jobs_queue_avail ON jobs(queue, reserved_at, available_at);

CREATE TABLE IF NOT EXISTS failed_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NULL,
    queue TEXT NOT NULL,
    payload TEXT NOT NULL,
    exception TEXT NOT NULL,
    failed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS webhooks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    url TEXT NOT NULL,
    secret TEXT NOT NULL,
    events TEXT NOT NULL DEFAULT '["*"]',
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_webhooks_tenant ON webhooks(tenant_id, is_active);

CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    webhook_id INTEGER NOT NULL,
    event TEXT NOT NULL,
    payload TEXT NOT NULL,
    response_code INTEGER NULL,
    response_body TEXT NULL,
    duration_ms INTEGER NULL,
    status TEXT NOT NULL DEFAULT 'pending', -- 'success', 'failed', 'pending'
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_webhook_deliveries_webhook ON webhook_deliveries(webhook_id, created_at DESC);
