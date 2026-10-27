-- Migration: 2026_10_27_000021_create_contacts_tables

CREATE TABLE IF NOT EXISTS contacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    type TEXT NOT NULL DEFAULT 'individual', -- 'individual' atau 'organization'
    code TEXT NOT NULL,
    name TEXT NOT NULL,
    legal_name TEXT NULL,
    id_number TEXT NULL, -- Encrypted NIK / Paspor (AES-256-GCM)
    tax_id TEXT NULL,    -- Encrypted NPWP / Tax ID (AES-256-GCM)
    email TEXT NULL,
    phone TEXT NULL,
    mobile TEXT NULL,    -- Nomor WhatsApp / Ponsel
    status TEXT NOT NULL DEFAULT 'active', -- 'active', 'inactive', 'archived'
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_contacts_tenant_code ON contacts(tenant_id, code);
CREATE INDEX IF NOT EXISTS idx_contacts_tenant_type ON contacts(tenant_id, type);
CREATE INDEX IF NOT EXISTS idx_contacts_tenant_status ON contacts(tenant_id, status);
CREATE INDEX IF NOT EXISTS idx_contacts_deleted_at ON contacts(deleted_at);

CREATE TABLE IF NOT EXISTS contact_roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    contact_id INTEGER NOT NULL,
    role TEXT NOT NULL, -- e.g. 'customer', 'vendor', 'employee', 'patient', 'practitioner', 'member'
    is_primary INTEGER NOT NULL DEFAULT 0,
    metadata TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_contact_roles_contact ON contact_roles(contact_id);
CREATE INDEX IF NOT EXISTS idx_contact_roles_role ON contact_roles(role);
CREATE UNIQUE INDEX IF NOT EXISTS idx_contact_roles_unique ON contact_roles(contact_id, role);

CREATE TABLE IF NOT EXISTS contact_addresses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    contact_id INTEGER NOT NULL,
    type TEXT NOT NULL DEFAULT 'primary', -- 'primary', 'billing', 'shipping', 'office', 'home'
    address_line1 TEXT NOT NULL,
    address_line2 TEXT NULL,
    city TEXT NULL,
    province TEXT NULL,
    postal_code TEXT NULL,
    country TEXT NULL DEFAULT 'ID',
    latitude REAL NULL,
    longitude REAL NULL,
    is_default INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_contact_addresses_contact ON contact_addresses(contact_id);
CREATE INDEX IF NOT EXISTS idx_contact_addresses_type ON contact_addresses(type);

CREATE TABLE IF NOT EXISTS contact_relations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_contact_id INTEGER NOT NULL, -- Misal Organisasi / Perusahaan
    child_contact_id INTEGER NOT NULL,  -- Misal Karyawan / Person
    relation_type TEXT NOT NULL DEFAULT 'employee_of', -- 'employee_of', 'subsidiary_of', 'family_of', 'representative_of'
    job_title TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    FOREIGN KEY (child_contact_id) REFERENCES contacts(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_contact_relations_parent ON contact_relations(parent_contact_id);
CREATE INDEX IF NOT EXISTS idx_contact_relations_child ON contact_relations(child_contact_id);
CREATE INDEX IF NOT EXISTS idx_contact_relations_type ON contact_relations(relation_type);
