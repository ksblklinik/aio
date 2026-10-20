<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Core\Database\Connection;
use Core\Database\Migrator;

$dbPath = dirname(__DIR__) . '/database.sqlite';
$db = new Connection("sqlite:{$dbPath}");
$migrator = new Migrator($db);

$migrator->createMigrationsTable();

$db->exec('
    CREATE TABLE IF NOT EXISTS tenants (
        id TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        slug TEXT UNIQUE NOT NULL,
        plan TEXT DEFAULT "starter",
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tenant_id TEXT NOT NULL,
        name TEXT NOT NULL,
        email TEXT UNIQUE NOT NULL,
        password TEXT NOT NULL,
        role TEXT DEFAULT "member",
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS projects (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tenant_id TEXT NOT NULL,
        name TEXT NOT NULL,
        status TEXT DEFAULT "Active",
        records_count INTEGER DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
');

// Seed default tenant if empty
$tenant = $db->selectOne("SELECT id FROM tenants WHERE id = 'tenant-acme'");
if (!$tenant) {
    $db->statement("
        INSERT INTO tenants (id, name, slug, plan) 
        VALUES ('tenant-acme', 'Acme Cloud Platform', 'acme', 'Enterprise Tier')
    ");
}

$projectCount = $db->selectOne("SELECT count(*) as cnt FROM projects WHERE tenant_id = 'tenant-acme'");
if (empty($projectCount['cnt'])) {
    $db->statement("
        INSERT INTO projects (tenant_id, name, status, records_count) 
        VALUES 
        ('tenant-acme', 'Customer Auth & RBAC Engine', 'Active', 24100),
        ('tenant-acme', 'Subscription Metering Pipeline', 'Active', 67300),
        ('tenant-acme', 'Realtime SSE WebSocket Push', 'Processing', 1420),
        ('tenant-acme', 'Audit Log Immutable Storage', 'Active', 189200)
    ");
}

echo "Database initialized at: {$dbPath}\n";
