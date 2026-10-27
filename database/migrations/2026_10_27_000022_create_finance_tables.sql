-- Migration: 2026_10_27_000022_create_finance_tables

CREATE TABLE IF NOT EXISTS financial_accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    name TEXT NOT NULL,
    type TEXT NOT NULL DEFAULT 'cash', -- 'cash', 'bank', 'ewallet'
    account_number TEXT NULL,          -- Encrypted nomor rekening bank (AES-256-GCM)
    bank_name TEXT NULL,
    currency TEXT NOT NULL DEFAULT 'IDR',
    initial_balance REAL NOT NULL DEFAULT 0.0,
    current_balance REAL NOT NULL DEFAULT 0.0,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_fin_accounts_tenant_code ON financial_accounts(tenant_id, code);
CREATE INDEX IF NOT EXISTS idx_fin_accounts_tenant_type ON financial_accounts(tenant_id, type);

CREATE TABLE IF NOT EXISTS financial_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    name TEXT NOT NULL,
    type TEXT NOT NULL, -- 'income', 'expense', 'asset', 'liability', 'equity'
    parent_id INTEGER NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES financial_categories(id) ON DELETE SET NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_fin_categories_tenant_code ON financial_categories(tenant_id, code);
CREATE INDEX IF NOT EXISTS idx_fin_categories_tenant_type ON financial_categories(tenant_id, type);

CREATE TABLE IF NOT EXISTS financial_transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    transaction_number TEXT NOT NULL,
    type TEXT NOT NULL, -- 'inflow', 'outflow', 'transfer'
    source_account_id INTEGER NOT NULL,
    target_account_id INTEGER NULL,
    category_id INTEGER NULL,
    contact_id INTEGER NULL,
    amount REAL NOT NULL,
    transaction_date DATE NOT NULL,
    status TEXT NOT NULL DEFAULT 'completed', -- 'draft', 'pending_approval', 'completed', 'cancelled'
    reference_number TEXT NULL,
    description TEXT NOT NULL,
    receipt_file_path TEXT NULL,
    approved_by INTEGER NULL,
    approved_at TIMESTAMP NULL,
    created_by INTEGER NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (source_account_id) REFERENCES financial_accounts(id) ON DELETE RESTRICT,
    FOREIGN KEY (target_account_id) REFERENCES financial_accounts(id) ON DELETE RESTRICT,
    FOREIGN KEY (category_id) REFERENCES financial_categories(id) ON DELETE SET NULL,
    FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_fin_transactions_tenant_num ON financial_transactions(tenant_id, transaction_number);
CREATE INDEX IF NOT EXISTS idx_fin_transactions_tenant_type ON financial_transactions(tenant_id, type);
CREATE INDEX IF NOT EXISTS idx_fin_transactions_tenant_date ON financial_transactions(tenant_id, transaction_date);
CREATE INDEX IF NOT EXISTS idx_fin_transactions_status ON financial_transactions(status);
