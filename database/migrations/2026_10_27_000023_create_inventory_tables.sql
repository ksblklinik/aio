-- Migration: 2026_10_27_000023_create_inventory_tables

CREATE TABLE IF NOT EXISTS inventory_units (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    name TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_inv_units_tenant_code ON inventory_units(tenant_id, code);

CREATE TABLE IF NOT EXISTS item_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    name TEXT NOT NULL,
    parent_id INTEGER NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES item_categories(id) ON DELETE SET NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_inv_categories_tenant_code ON item_categories(tenant_id, code);

CREATE TABLE IF NOT EXISTS items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    sku TEXT NULL,
    barcode TEXT NULL,
    name TEXT NOT NULL,
    type TEXT NOT NULL DEFAULT 'goods', -- 'goods', 'service', 'raw_material', 'bundle'
    category_id INTEGER NULL,
    unit_id INTEGER NULL,
    cost_price REAL NOT NULL DEFAULT 0.0,
    selling_price REAL NOT NULL DEFAULT 0.0,
    current_stock REAL NOT NULL DEFAULT 0.0,
    min_stock_alert REAL NOT NULL DEFAULT 0.0,
    track_inventory INTEGER NOT NULL DEFAULT 1,
    is_active INTEGER NOT NULL DEFAULT 1,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES item_categories(id) ON DELETE SET NULL,
    FOREIGN KEY (unit_id) REFERENCES inventory_units(id) ON DELETE SET NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_items_tenant_code ON items(tenant_id, code);
CREATE INDEX IF NOT EXISTS idx_items_tenant_type ON items(tenant_id, type);
CREATE INDEX IF NOT EXISTS idx_items_tenant_sku ON items(tenant_id, sku);
CREATE INDEX IF NOT EXISTS idx_items_tenant_barcode ON items(tenant_id, barcode);

CREATE TABLE IF NOT EXISTS inventory_movements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    movement_number TEXT NOT NULL,
    item_id INTEGER NOT NULL,
    type TEXT NOT NULL, -- 'in', 'out', 'adjustment', 'opname'
    quantity REAL NOT NULL,
    stock_before REAL NOT NULL,
    stock_after REAL NOT NULL,
    unit_cost REAL NULL,
    reference_type TEXT NULL,
    reference_id TEXT NULL,
    notes TEXT NULL,
    created_by INTEGER NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_inv_movements_tenant_num ON inventory_movements(tenant_id, movement_number);
CREATE INDEX IF NOT EXISTS idx_inv_movements_tenant_item ON inventory_movements(tenant_id, item_id);
CREATE INDEX IF NOT EXISTS idx_inv_movements_tenant_type ON inventory_movements(tenant_id, type);
