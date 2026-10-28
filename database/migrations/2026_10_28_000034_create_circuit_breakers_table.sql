-- Migration: Create Circuit Breakers Table
-- Description: Stores stateful circuit breaker health states across processes, CLI workers, and web requests.

CREATE TABLE IF NOT EXISTS circuit_breakers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    service_name TEXT NOT NULL UNIQUE,
    state TEXT NOT NULL DEFAULT 'closed', -- 'closed', 'open', 'half_open'
    failure_count INTEGER NOT NULL DEFAULT 0,
    success_count INTEGER NOT NULL DEFAULT 0,
    failure_threshold INTEGER NOT NULL DEFAULT 5,
    cooldown_seconds INTEGER NOT NULL DEFAULT 60,
    last_failure_at TEXT,
    last_success_at TEXT,
    last_state_change_at TEXT NOT NULL,
    last_error TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_circuit_breakers_service ON circuit_breakers(service_name);
