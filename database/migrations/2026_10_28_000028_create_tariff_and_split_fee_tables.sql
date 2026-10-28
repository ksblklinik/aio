-- ============================================================================
-- Migration: 2026_10_28_000028_create_tariff_and_split_fee_tables.sql
-- Description: Universal multi-payer price books, tiered rate matrix, and revenue split fee calculation engine
-- ============================================================================

CREATE TABLE IF NOT EXISTS tariff_price_books (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    name TEXT NOT NULL,
    description TEXT NULL,
    payer_contact_id INTEGER NULL,
    currency TEXT NOT NULL DEFAULT 'IDR',
    is_active INTEGER NOT NULL DEFAULT 1,
    is_default INTEGER NOT NULL DEFAULT 0,
    valid_from TEXT NULL,
    valid_until TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (payer_contact_id) REFERENCES contacts(id) ON DELETE SET NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_tariff_price_books_tenant_code ON tariff_price_books(tenant_id, code);
CREATE INDEX IF NOT EXISTS idx_tariff_price_books_tenant_active ON tariff_price_books(tenant_id, is_active);
CREATE INDEX IF NOT EXISTS idx_tariff_price_books_tenant_payer ON tariff_price_books(tenant_id, payer_contact_id);

CREATE TABLE IF NOT EXISTS tariff_rates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    price_book_id INTEGER NOT NULL,
    item_id INTEGER NOT NULL,
    room_type TEXT NULL,
    base_rate REAL NOT NULL,
    min_qty REAL NOT NULL DEFAULT 1.0,
    coverage_type TEXT NOT NULL DEFAULT 'fixed',
    coverage_amount REAL NULL,
    patient_copay REAL NOT NULL DEFAULT 0.0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (price_book_id) REFERENCES tariff_price_books(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_tariff_rates_tenant_book_item ON tariff_rates(tenant_id, price_book_id, item_id);
CREATE INDEX IF NOT EXISTS idx_tariff_rates_tenant_item ON tariff_rates(tenant_id, item_id);

CREATE TABLE IF NOT EXISTS tariff_split_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    item_id INTEGER NULL,
    price_book_id INTEGER NULL,
    name TEXT NOT NULL,
    recipient_type TEXT NOT NULL, -- performer, facility, organization, account
    default_beneficiary_contact_id INTEGER NULL,
    split_type TEXT NOT NULL,     -- percentage, fixed_amount
    split_value REAL NOT NULL,
    target_account_code TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
    FOREIGN KEY (price_book_id) REFERENCES tariff_price_books(id) ON DELETE CASCADE,
    FOREIGN KEY (default_beneficiary_contact_id) REFERENCES contacts(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_tariff_split_rules_tenant_item ON tariff_split_rules(tenant_id, item_id);
CREATE INDEX IF NOT EXISTS idx_tariff_split_rules_tenant_book ON tariff_split_rules(tenant_id, price_book_id);

CREATE TABLE IF NOT EXISTS tariff_fee_allocations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    invoice_id INTEGER NOT NULL,
    invoice_item_id INTEGER NULL,
    split_rule_id INTEGER NULL,
    item_id INTEGER NOT NULL,
    beneficiary_contact_id INTEGER NULL,
    beneficiary_user_id INTEGER NULL,
    component_name TEXT NOT NULL,
    amount REAL NOT NULL,
    status TEXT NOT NULL DEFAULT 'accrued', -- accrued, paid, cancelled
    settlement_reference TEXT NULL,
    journal_entry_id INTEGER NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    FOREIGN KEY (split_rule_id) REFERENCES tariff_split_rules(id) ON DELETE SET NULL,
    FOREIGN KEY (beneficiary_contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
    FOREIGN KEY (beneficiary_user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_tariff_allocations_tenant_invoice ON tariff_fee_allocations(tenant_id, invoice_id);
CREATE INDEX IF NOT EXISTS idx_tariff_allocations_tenant_beneficiary ON tariff_fee_allocations(tenant_id, beneficiary_contact_id);
CREATE INDEX IF NOT EXISTS idx_tariff_allocations_tenant_status ON tariff_fee_allocations(tenant_id, status);
