-- ============================================================================
-- Migration: 2026_10_28_000030_create_document_seals_tables.sql
-- Description: Universal document cryptographic seal, digital signature verification, and audit logs
-- ============================================================================

CREATE TABLE IF NOT EXISTS document_seals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    verification_token TEXT NOT NULL,
    document_type TEXT NOT NULL,
    document_number TEXT NOT NULL,
    title TEXT NOT NULL,
    issuer_name TEXT NOT NULL,
    signer_name TEXT NOT NULL,
    signer_role TEXT NULL,
    signer_credential TEXT NULL,
    subject_name TEXT NOT NULL,
    subject_identifier TEXT NULL,
    subject_masked_name TEXT NULL,
    payload_snapshot TEXT NOT NULL,
    canonical_hash TEXT NOT NULL,
    signature TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    revoked_at TIMESTAMP NULL,
    revocation_reason TEXT NULL,
    valid_from DATE NULL,
    valid_until DATE NULL,
    metadata TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_document_seals_token ON document_seals(verification_token);
CREATE INDEX IF NOT EXISTS idx_document_seals_tenant_doc ON document_seals(tenant_id, document_type, document_number);
CREATE INDEX IF NOT EXISTS idx_document_seals_hash ON document_seals(canonical_hash);

CREATE TABLE IF NOT EXISTS document_verification_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    document_seal_id INTEGER NOT NULL,
    verification_token TEXT NOT NULL,
    verification_result TEXT NOT NULL,
    ip_address TEXT NULL,
    user_agent TEXT NULL,
    referer TEXT NULL,
    verified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_seal_id) REFERENCES document_seals(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_doc_verify_seal ON document_verification_logs(document_seal_id);
CREATE INDEX IF NOT EXISTS idx_doc_verify_time ON document_verification_logs(verified_at);
