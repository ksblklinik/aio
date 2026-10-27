-- Migration: 2026_10_27_000020_add_hash_chain_to_audit_logs

ALTER TABLE audit_logs ADD COLUMN prev_hash TEXT NULL;
ALTER TABLE audit_logs ADD COLUMN current_hash TEXT NULL;

CREATE INDEX IF NOT EXISTS idx_audit_logs_current_hash ON audit_logs(current_hash);
