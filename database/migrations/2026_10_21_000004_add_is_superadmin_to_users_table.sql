-- Migration: 2026_10_21_000004_add_is_superadmin_to_users_table

ALTER TABLE users ADD COLUMN is_superadmin INTEGER NOT NULL DEFAULT 0;
