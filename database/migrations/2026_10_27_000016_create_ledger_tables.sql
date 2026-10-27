-- @up
CREATE TABLE IF NOT EXISTS ledgers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    account_type TEXT NOT NULL,
    account_id TEXT NOT NULL,
    currency TEXT NOT NULL DEFAULT 'IDR',
    balance REAL NOT NULL DEFAULT 0.0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(tenant_id, account_type, account_id)
);

CREATE INDEX IF NOT EXISTS idx_ledgers_tenant_acc ON ledgers(tenant_id, account_type, account_id);

CREATE TABLE IF NOT EXISTS ledger_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    ledger_id INTEGER NOT NULL,
    direction TEXT NOT NULL, -- 'debit' | 'credit'
    amount REAL NOT NULL,
    balance_after REAL NOT NULL,
    reference_type TEXT,
    reference_id TEXT,
    description TEXT,
    metadata TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(ledger_id) REFERENCES ledgers(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_ledger_entries_tenant_ref ON ledger_entries(tenant_id, reference_type, reference_id);

-- @down
DROP INDEX IF EXISTS idx_ledger_entries_tenant_ref;
DROP TABLE IF EXISTS ledger_entries;
DROP INDEX IF EXISTS idx_ledgers_tenant_acc;
DROP TABLE IF EXISTS ledgers;
