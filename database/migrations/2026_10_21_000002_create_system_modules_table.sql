-- Migration: 2026_10_21_000002_create_system_modules_table

CREATE TABLE IF NOT EXISTS system_modules (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    version TEXT NOT NULL DEFAULT '1.0.0',
    author TEXT NOT NULL DEFAULT 'aio Core',
    description TEXT,
    category TEXT NOT NULL DEFAULT 'General',
    icon TEXT NOT NULL DEFAULT 'Layers',
    route TEXT NOT NULL,
    dependencies TEXT DEFAULT '[]',
    is_core INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    table_name TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
