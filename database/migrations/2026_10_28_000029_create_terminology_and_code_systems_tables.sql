-- ============================================================================
-- Migration: 2026_10_28_000029_create_terminology_and_code_systems_tables.sql
-- Description: Universal semantic coding systems, concepts dictionary, and cross-reference mappings
-- ============================================================================

CREATE TABLE IF NOT EXISTS terminology_code_systems (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NULL,
    code TEXT NOT NULL,
    name TEXT NOT NULL,
    uri TEXT NULL,
    version TEXT NULL,
    publisher TEXT NULL,
    description TEXT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    is_system INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_terminology_cs_code ON terminology_code_systems(code);
CREATE INDEX IF NOT EXISTS idx_terminology_cs_tenant ON terminology_code_systems(tenant_id);

CREATE TABLE IF NOT EXISTS terminology_concepts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NULL,
    code_system_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    display_name TEXT NOT NULL,
    description TEXT NULL,
    parent_code TEXT NULL,
    synonyms TEXT NULL,
    properties TEXT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (code_system_id) REFERENCES terminology_code_systems(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_terminology_concepts_lookup ON terminology_concepts(code_system_id, code);
CREATE INDEX IF NOT EXISTS idx_terminology_concepts_name ON terminology_concepts(display_name);
CREATE INDEX IF NOT EXISTS idx_terminology_concepts_parent ON terminology_concepts(parent_code);

CREATE TABLE IF NOT EXISTS terminology_mappings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    source_system_id INTEGER NOT NULL,
    source_code TEXT NOT NULL,
    target_system_id INTEGER NOT NULL,
    target_code TEXT NOT NULL,
    equivalence TEXT NOT NULL DEFAULT 'equivalent',
    comments TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (source_system_id) REFERENCES terminology_code_systems(id) ON DELETE CASCADE,
    FOREIGN KEY (target_system_id) REFERENCES terminology_code_systems(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_terminology_map_lookup ON terminology_mappings(tenant_id, source_system_id, source_code);
