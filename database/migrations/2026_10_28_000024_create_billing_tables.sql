-- Migration: 2026_10_28_000024_create_billing_tables

CREATE TABLE IF NOT EXISTS invoices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    invoice_number TEXT NOT NULL,
    type TEXT NOT NULL DEFAULT 'invoice', -- 'invoice' (Sales / AR), 'bill' (Purchase / AP)
    contact_id INTEGER NULL,
    contact_name TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'draft', -- 'draft', 'issued', 'partially_paid', 'paid', 'cancelled'
    issue_date TEXT NOT NULL,
    due_date TEXT NULL,
    currency TEXT NOT NULL DEFAULT 'IDR',
    subtotal REAL NOT NULL DEFAULT 0.0,
    discount_total REAL NOT NULL DEFAULT 0.0,
    tax_total REAL NOT NULL DEFAULT 0.0,
    grand_total REAL NOT NULL DEFAULT 0.0,
    amount_paid REAL NOT NULL DEFAULT 0.0,
    amount_due REAL NOT NULL DEFAULT 0.0,
    payment_status TEXT NOT NULL DEFAULT 'unpaid', -- 'unpaid', 'partially_paid', 'paid'
    notes TEXT NULL,
    created_by INTEGER NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_invoices_tenant_num ON invoices(tenant_id, invoice_number);
CREATE INDEX IF NOT EXISTS idx_invoices_tenant_type ON invoices(tenant_id, type);
CREATE INDEX IF NOT EXISTS idx_invoices_tenant_status ON invoices(tenant_id, status);
CREATE INDEX IF NOT EXISTS idx_invoices_tenant_payment_status ON invoices(tenant_id, payment_status);
CREATE INDEX IF NOT EXISTS idx_invoices_tenant_contact ON invoices(tenant_id, contact_id);

CREATE TABLE IF NOT EXISTS invoice_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    invoice_id INTEGER NOT NULL,
    item_id INTEGER NULL,
    item_name TEXT NOT NULL,
    item_type TEXT NOT NULL DEFAULT 'goods', -- 'goods', 'service', 'other'
    quantity REAL NOT NULL DEFAULT 1.0,
    unit_price REAL NOT NULL DEFAULT 0.0,
    discount REAL NOT NULL DEFAULT 0.0,
    tax_rate REAL NOT NULL DEFAULT 0.0,
    subtotal REAL NOT NULL DEFAULT 0.0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_invoice_items_tenant_invoice ON invoice_items(tenant_id, invoice_id);
CREATE INDEX IF NOT EXISTS idx_invoice_items_tenant_item ON invoice_items(tenant_id, item_id);

CREATE TABLE IF NOT EXISTS invoice_payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    invoice_id INTEGER NOT NULL,
    payment_number TEXT NOT NULL,
    account_id INTEGER NULL,
    cash_flow_id INTEGER NULL,
    payment_method TEXT NOT NULL DEFAULT 'cash', -- 'cash', 'bank_transfer', 'qris', 'card', 'other'
    amount REAL NOT NULL,
    payment_date TEXT NOT NULL,
    reference_note TEXT NULL,
    created_by INTEGER NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES financial_accounts(id) ON DELETE SET NULL,
    FOREIGN KEY (cash_flow_id) REFERENCES cash_flows(id) ON DELETE SET NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_inv_payments_tenant_num ON invoice_payments(tenant_id, payment_number);
CREATE INDEX IF NOT EXISTS idx_inv_payments_tenant_invoice ON invoice_payments(tenant_id, invoice_id);
