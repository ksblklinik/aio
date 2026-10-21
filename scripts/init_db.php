<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Core\Database\Connection;
use Core\Database\Migrator;

$dbPath = dirname(__DIR__) . '/database.sqlite';
$db = new Connection("sqlite:{$dbPath}");
$migrator = new Migrator($db);

echo "[1/3] Running database migrations...\n";
$migrationsDir = dirname(__DIR__) . '/database/migrations';
$executed = $migrator->runMigrationsFrom($migrationsDir);

if (!empty($executed)) {
    foreach ($executed as $m) {
        echo " [+] Executed migration: {$m}\n";
    }
} else {
    echo " [i] Migrations already up to date.\n";
}

echo "[2/3] Seeding demo tenants & users...\n";

// Password default: "password"
$defaultPasswordHash = password_hash('password', PASSWORD_BCRYPT);

// Seed Tenants
$tenants = [
    ['id' => 1, 'name' => 'Acme Corp', 'slug' => 'acme', 'plan' => 'Enterprise Pro'],
    ['id' => 2, 'name' => 'Starlight Labs', 'slug' => 'starlight', 'plan' => 'Startup Growth'],
];

foreach ($tenants as $t) {
    $exists = $db->selectOne("SELECT id FROM tenants WHERE slug = ?", [$t['slug']]);
    if (!$exists) {
        $db->statement(
            "INSERT INTO tenants (id, name, slug, plan) VALUES (?, ?, ?, ?)",
            [$t['id'], $t['name'], $t['slug'], $t['plan']]
        );
        echo " [+] Created tenant: {$t['name']} ({$t['slug']})\n";
    }
}

// Seed Users
$users = [
    [
        'id' => 1,
        'name' => 'John Doe (Owner)',
        'email' => 'john@acme.com',
        'password_hash' => $defaultPasswordHash,
    ],
    [
        'id' => 2,
        'name' => 'Sarah Smith (Member)',
        'email' => 'sarah@acme.com',
        'password_hash' => $defaultPasswordHash,
    ],
    [
        'id' => 3,
        'name' => 'Alex Rivera (Owner)',
        'email' => 'alex@starlight.io',
        'password_hash' => $defaultPasswordHash,
    ],
];

foreach ($users as $u) {
    $exists = $db->selectOne("SELECT id FROM users WHERE email = ?", [$u['email']]);
    if (!$exists) {
        $db->statement(
            "INSERT INTO users (id, name, email, password_hash) VALUES (?, ?, ?, ?)",
            [$u['id'], $u['name'], $u['email'], $u['password_hash']]
        );
        echo " [+] Created user: {$u['name']} ({$u['email']})\n";
    }
}

// Seed Pivot Memberships & Roles
// User 1 (John): Owner di Acme Corp (1), Member di Starlight Labs (2)
// User 2 (Sarah): Member di Acme Corp (1)
// User 3 (Alex): Owner di Starlight Labs (2)
$memberships = [
    ['tenant_id' => 1, 'user_id' => 1, 'role' => 'owner'],
    ['tenant_id' => 2, 'user_id' => 1, 'role' => 'member'],
    ['tenant_id' => 1, 'user_id' => 2, 'role' => 'member'],
    ['tenant_id' => 2, 'user_id' => 3, 'role' => 'owner'],
];

foreach ($memberships as $m) {
    $exists = $db->selectOne(
        "SELECT id FROM tenant_user WHERE tenant_id = ? AND user_id = ?",
        [$m['tenant_id'], $m['user_id']]
    );
    if (!$exists) {
        $db->statement(
            "INSERT INTO tenant_user (tenant_id, user_id, role) VALUES (?, ?, ?)",
            [$m['tenant_id'], $m['user_id'], $m['role']]
        );
        echo " [+] Linked User {$m['user_id']} to Tenant {$m['tenant_id']} as {$m['role']}\n";
    }
}

echo "[3/3] Seeding demo projects & tasks...\n";
// Projects for Tenant 1 (Acme Corp)
$projects = [
    [
        'tenant_id' => 1,
        'user_id' => 1,
        'name' => 'Cloud Migration & Infrastructure',
        'description' => 'Migrate high-load microservices to Kubernetes cluster with zero downtime.',
        'status' => 'in_progress',
        'tasks' => [
            ['title' => 'Audit existing container configurations', 'is_completed' => 1],
            ['title' => 'Setup multi-region failover database replicas', 'is_completed' => 0],
            ['title' => 'Run load testing with 50,000 simulated users', 'is_completed' => 0],
        ]
    ],
    [
        'tenant_id' => 1,
        'user_id' => 2,
        'name' => 'Design System 2.0 (React 19)',
        'description' => 'Unify design tokens, dark mode palette, and accessible form controls.',
        'status' => 'completed',
        'tasks' => [
            ['title' => 'Extract Figma token dictionary', 'is_completed' => 1],
            ['title' => 'Build headless modal & dropdown components', 'is_completed' => 1],
        ]
    ],
    // Projects for Tenant 2 (Starlight Labs)
    [
        'tenant_id' => 2,
        'user_id' => 3,
        'name' => 'AI Automated Quality Inspector',
        'description' => 'Fine-tune vision transformer model on custom defect dataset.',
        'status' => 'in_progress',
        'tasks' => [
            ['title' => 'Collect 10,000 sample edge cases', 'is_completed' => 1],
            ['title' => 'Deploy ONNX model to low-latency edge runtime', 'is_completed' => 0],
        ]
    ],
];

foreach ($projects as $p) {
    $existing = $db->selectOne(
        "SELECT id FROM projects WHERE tenant_id = ? AND name = ?",
        [$p['tenant_id'], $p['name']]
    );

    if (!$existing) {
        $projectId = (int) $db->insert('projects', [
            'tenant_id' => $p['tenant_id'],
            'user_id' => $p['user_id'],
            'name' => $p['name'],
            'description' => $p['description'],
            'status' => $p['status'],
        ]);
        echo " [+] Created project: {$p['name']} (Tenant {$p['tenant_id']})\n";

        foreach ($p['tasks'] as $t) {
            $db->insert('tasks', [
                'tenant_id' => $p['tenant_id'],
                'project_id' => $projectId,
                'title' => $t['title'],
                'is_completed' => $t['is_completed'],
            ]);
        }
    }
}

echo "\nDatabase seeded successfully at: {$dbPath}\n";
