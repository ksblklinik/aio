-- Up
CREATE TABLE IF NOT EXISTS payment_transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    reference_no VARCHAR(64) UNIQUE NOT NULL,
    driver VARCHAR(32) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'IDR',
    status VARCHAR(20) DEFAULT 'pending',
    payment_method VARCHAR(32) NULL,
    customer_name VARCHAR(150) NULL,
    customer_email VARCHAR(150) NULL,
    customer_phone VARCHAR(32) NULL,
    gateway_reference VARCHAR(128) NULL,
    checkout_url TEXT NULL,
    metadata TEXT NULL,
    paid_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_payment_transactions_tenant ON payment_transactions(tenant_id, status);
CREATE INDEX IF NOT EXISTS idx_payment_transactions_ref ON payment_transactions(reference_no);

CREATE TABLE IF NOT EXISTS tenant_entitlements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    feature_key VARCHAR(64) NOT NULL,
    is_enabled INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(tenant_id, feature_key)
);

CREATE TABLE IF NOT EXISTS tenant_quotas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    metric_key VARCHAR(64) NOT NULL,
    quota_limit INTEGER NOT NULL,
    current_usage INTEGER DEFAULT 0,
    period VARCHAR(20) DEFAULT 'monthly',
    period_start DATETIME NOT NULL,
    period_end DATETIME NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(tenant_id, metric_key)
);

CREATE INDEX IF NOT EXISTS idx_tenant_quotas_metric ON tenant_quotas(tenant_id, metric_key);

-- Down
DROP TABLE IF EXISTS tenant_quotas;
DROP TABLE IF EXISTS tenant_entitlements;
DROP TABLE IF EXISTS payment_transactions;
