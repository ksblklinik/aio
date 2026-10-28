-- Migration: Create Locks Table for Universal Atomic Mutex & Concurrency Lock
-- SPEC-037: Universal Atomic Mutex & Distributed Concurrency Lock Engine

CREATE TABLE IF NOT EXISTS locks (
    key TEXT PRIMARY KEY,
    owner TEXT NOT NULL,
    expires_at INTEGER NOT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_locks_expires_at ON locks(expires_at);
