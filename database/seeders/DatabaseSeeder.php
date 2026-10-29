<?php

declare(strict_types=1);

namespace Database\Seeders;

use Core\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $defaultPasswordHash = password_hash('password', PASSWORD_BCRYPT);

        // 1. Seed Default Tenants
        $tenants = [
            ['id' => 1, 'name' => 'Acme Corp', 'slug' => 'acme', 'plan' => 'Enterprise Pro'],
            ['id' => 2, 'name' => 'Starlight Labs', 'slug' => 'starlight', 'plan' => 'Startup Growth'],
        ];

        foreach ($tenants as $t) {
            $exists = $this->db->selectOne("SELECT id FROM tenants WHERE id = ? OR slug = ?", [$t['id'], $t['slug']]);
            if (!$exists) {
                $this->db->statement(
                    "INSERT INTO tenants (id, name, slug, plan) VALUES (?, ?, ?, ?)",
                    [$t['id'], $t['name'], $t['slug'], $t['plan']]
                );
            }
        }

        // 2. Seed Default Users
        $users = [
            [
                'id' => 1,
                'name' => 'John Doe (Owner)',
                'email' => 'john@acme.com',
                'password_hash' => $defaultPasswordHash,
                'is_superadmin' => 0,
            ],
            [
                'id' => 2,
                'name' => 'Sarah Smith (Member)',
                'email' => 'sarah@acme.com',
                'password_hash' => $defaultPasswordHash,
                'is_superadmin' => 0,
            ],
            [
                'id' => 3,
                'name' => 'Alex Rivera (Owner)',
                'email' => 'alex@starlight.io',
                'password_hash' => $defaultPasswordHash,
                'is_superadmin' => 0,
            ],
            [
                'id' => 4,
                'name' => 'System Superadmin',
                'email' => 'superadmin@aio.local',
                'password_hash' => $defaultPasswordHash,
                'is_superadmin' => 1,
            ],
        ];

        foreach ($users as $u) {
            $exists = $this->db->selectOne("SELECT id FROM users WHERE id = ? OR email = ?", [$u['id'], $u['email']]);
            if (!$exists) {
                $this->db->statement(
                    "INSERT INTO users (id, name, email, password_hash, is_superadmin) VALUES (?, ?, ?, ?, ?)",
                    [$u['id'], $u['name'], $u['email'], $u['password_hash'], $u['is_superadmin']]
                );
            }
        }

        // 3. Seed Tenant Memberships
        $memberships = [
            ['tenant_id' => 1, 'user_id' => 1, 'role' => 'owner'],
            ['tenant_id' => 2, 'user_id' => 1, 'role' => 'member'],
            ['tenant_id' => 1, 'user_id' => 2, 'role' => 'member'],
            ['tenant_id' => 2, 'user_id' => 3, 'role' => 'owner'],
            ['tenant_id' => 1, 'user_id' => 4, 'role' => 'owner'],
            ['tenant_id' => 2, 'user_id' => 4, 'role' => 'owner'],
        ];

        foreach ($memberships as $m) {
            $exists = $this->db->selectOne(
                "SELECT 1 FROM tenant_user WHERE tenant_id = ? AND user_id = ?",
                [$m['tenant_id'], $m['user_id']]
            );
            if (!$exists) {
                $this->db->statement(
                    "INSERT INTO tenant_user (tenant_id, user_id, role) VALUES (?, ?, ?)",
                    [$m['tenant_id'], $m['user_id'], $m['role']]
                );
            }
        }
    }
}
