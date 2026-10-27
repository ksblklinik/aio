-- Migration: 2026_10_28_000025_create_facility_tables

CREATE TABLE IF NOT EXISTS facilities_locations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    name TEXT NOT NULL,
    type TEXT NOT NULL DEFAULT 'headquarters', -- 'headquarters', 'branch', 'site', 'warehouse', 'outpost', 'other'
    address TEXT NULL,
    city TEXT NULL,
    phone TEXT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_fac_loc_tenant_code ON facilities_locations(tenant_id, code);
CREATE INDEX IF NOT EXISTS idx_fac_loc_tenant_type ON facilities_locations(tenant_id, type);

CREATE TABLE IF NOT EXISTS facilities_rooms (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    location_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    name TEXT NOT NULL,
    floor TEXT NOT NULL DEFAULT '1',
    type TEXT NOT NULL DEFAULT 'service', -- 'service', 'storage', 'inpatient', 'office', 'meeting', 'procedure', 'other'
    capacity INTEGER NOT NULL DEFAULT 1,
    notes TEXT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (location_id) REFERENCES facilities_locations(id) ON DELETE RESTRICT
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_fac_room_tenant_code ON facilities_rooms(tenant_id, code);
CREATE INDEX IF NOT EXISTS idx_fac_room_tenant_location ON facilities_rooms(tenant_id, location_id);
CREATE INDEX IF NOT EXISTS idx_fac_room_tenant_type ON facilities_rooms(tenant_id, type);

CREATE TABLE IF NOT EXISTS facilities_spaces (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    room_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    name TEXT NOT NULL,
    type TEXT NOT NULL DEFAULT 'bed', -- 'bed', 'desk', 'chair', 'dock', 'booth', 'other'
    status TEXT NOT NULL DEFAULT 'available', -- 'available', 'occupied', 'maintenance', 'reserved'
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES facilities_rooms(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_fac_space_tenant_code ON facilities_spaces(tenant_id, code);
CREATE INDEX IF NOT EXISTS idx_fac_space_tenant_room ON facilities_spaces(tenant_id, room_id);
CREATE INDEX IF NOT EXISTS idx_fac_space_tenant_status ON facilities_spaces(tenant_id, status);
