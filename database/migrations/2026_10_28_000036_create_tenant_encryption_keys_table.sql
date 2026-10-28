-- Migration: Create Tenant Encryption Keys Table
-- Description: Stores per-tenant isolated cryptographic salt and derived keys for zero-leakage crypto-shredding and sensitive PII protection.

CREATE TABLE IF NOT EXISTS tenant_encryption_keys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL UNIQUE,
    key_salt TEXT NOT NULL,
    algorithm TEXT NOT NULL DEFAULT 'aes-256-cbc',
    is_shredded INTEGER NOT NULL DEFAULT 0,
    shredded_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_tenant_keys_tenant ON tenant_encryption_keys(tenant_id);
