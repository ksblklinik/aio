-- Migration: Create Reconciliation Audit Logs Table
-- Description: Stores cross-module data drift, reconciliation findings, and automated healing resolution history.

CREATE TABLE IF NOT EXISTS reconciliation_audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    pillar TEXT NOT NULL,
    severity TEXT NOT NULL, -- 'info', 'warning', 'critical'
    entity_type TEXT NOT NULL,
    entity_id TEXT NOT NULL,
    description TEXT NOT NULL,
    expected_value TEXT,
    actual_value TEXT,
    resolved INTEGER NOT NULL DEFAULT 0,
    resolved_at TEXT,
    resolution_notes TEXT,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_reconciliation_tenant ON reconciliation_audit_logs(tenant_id, pillar, resolved);
CREATE INDEX IF NOT EXISTS idx_reconciliation_entity ON reconciliation_audit_logs(entity_type, entity_id);
