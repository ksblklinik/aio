-- Migration: 2026_10_28_000026_add_multi_warehouse_and_batches

CREATE TABLE IF NOT EXISTS item_batches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    item_id INTEGER NOT NULL,
    batch_number TEXT NOT NULL,
    expiry_date TEXT NOT NULL, -- 'YYYY-MM-DD'
    initial_quantity REAL NOT NULL DEFAULT 0.0,
    current_quantity REAL NOT NULL DEFAULT 0.0,
    cost_price REAL NOT NULL DEFAULT 0.0,
    status TEXT NOT NULL DEFAULT 'active', -- 'active', 'quarantined', 'expired'
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_item_batches_tenant_item_batch ON item_batches(tenant_id, item_id, batch_number);
CREATE INDEX IF NOT EXISTS idx_item_batches_tenant_expiry ON item_batches(tenant_id, expiry_date);
CREATE INDEX IF NOT EXISTS idx_item_batches_tenant_status ON item_batches(tenant_id, status);

CREATE TABLE IF NOT EXISTS inventory_stock_locations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    item_id INTEGER NOT NULL,
    room_id INTEGER NOT NULL,
    batch_id INTEGER NOT NULL DEFAULT 0,
    quantity REAL NOT NULL DEFAULT 0.0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES facilities_rooms(id) ON DELETE RESTRICT
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_inv_stock_loc_unique ON inventory_stock_locations(tenant_id, item_id, room_id, batch_id);
CREATE INDEX IF NOT EXISTS idx_inv_stock_loc_room ON inventory_stock_locations(tenant_id, room_id);
CREATE INDEX IF NOT EXISTS idx_inv_stock_loc_item ON inventory_stock_locations(tenant_id, item_id);

CREATE TABLE IF NOT EXISTS inventory_transfers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    transfer_number TEXT NOT NULL,
    source_room_id INTEGER NOT NULL,
    destination_room_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'completed', -- 'draft', 'pending', 'completed', 'cancelled'
    transfer_date TEXT NOT NULL,
    notes TEXT NULL,
    created_by INTEGER NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (source_room_id) REFERENCES facilities_rooms(id) ON DELETE RESTRICT,
    FOREIGN KEY (destination_room_id) REFERENCES facilities_rooms(id) ON DELETE RESTRICT
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_inv_trf_tenant_num ON inventory_transfers(tenant_id, transfer_number);
CREATE INDEX IF NOT EXISTS idx_inv_trf_tenant_source ON inventory_transfers(tenant_id, source_room_id);
CREATE INDEX IF NOT EXISTS idx_inv_trf_tenant_dest ON inventory_transfers(tenant_id, destination_room_id);

CREATE TABLE IF NOT EXISTS inventory_transfer_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    transfer_id INTEGER NOT NULL,
    item_id INTEGER NOT NULL,
    batch_id INTEGER NOT NULL DEFAULT 0,
    quantity REAL NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (transfer_id) REFERENCES inventory_transfers(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_inv_trf_items_transfer ON inventory_transfer_items(transfer_id);
CREATE INDEX IF NOT EXISTS idx_inv_trf_items_item ON inventory_transfer_items(item_id);

ALTER TABLE inventory_movements ADD COLUMN room_id INTEGER NULL;
ALTER TABLE inventory_movements ADD COLUMN batch_id INTEGER NULL;
