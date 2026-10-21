<?php

declare(strict_types=1);

namespace App\Modules\Workspaces\Models;

use Core\Database\Connection;

class Tenant
{
    public function __construct(
        public ?int $id = null,
        public string $name = '',
        public string $slug = '',
        public string $plan = 'pro',
        public ?string $createdAt = null
    ) {
    }

    public static function fromArray(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            name: (string) ($row['name'] ?? ''),
            slug: (string) ($row['slug'] ?? ''),
            plan: (string) ($row['plan'] ?? 'pro'),
            createdAt: isset($row['created_at']) ? (string) $row['created_at'] : null
        );
    }

    public static function findById(Connection $db, int $id): ?self
    {
        $row = $db->selectOne("SELECT * FROM tenants WHERE id = ?", [$id]);
        return $row ? self::fromArray($row) : null;
    }

    public static function findBySlug(Connection $db, string $slug): ?self
    {
        $row = $db->selectOne("SELECT * FROM tenants WHERE slug = ?", [trim(strtolower($slug))]);
        return $row ? self::fromArray($row) : null;
    }

    public static function create(Connection $db, array $data): self
    {
        $id = $db->insert('tenants', [
            'name' => trim((string) ($data['name'] ?? '')),
            'slug' => trim(strtolower((string) ($data['slug'] ?? ''))),
            'plan' => (string) ($data['plan'] ?? 'pro'),
        ]);

        return new self(
            id: (int) $id,
            name: (string) $data['name'],
            slug: trim(strtolower((string) $data['slug'])),
            plan: (string) ($data['plan'] ?? 'pro')
        );
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function getMembers(Connection $db): array
    {
        if (!$this->id) {
            return [];
        }

        return $db->selectAll(
            "SELECT u.id, u.name, u.email, tu.role, tu.created_at as joined_at 
             FROM users u 
             JOIN tenant_user tu ON tu.user_id = u.id 
             WHERE tu.tenant_id = ? 
             ORDER BY u.name ASC",
            [$this->id]
        );
    }

    public function addMember(Connection $db, int $userId, string $role = 'member'): void
    {
        if (!$this->id) {
            return;
        }

        $existing = $db->selectOne(
            "SELECT id FROM tenant_user WHERE tenant_id = ? AND user_id = ?",
            [$this->id, $userId]
        );

        if ($existing) {
            $db->statement(
                "UPDATE tenant_user SET role = ? WHERE id = ?",
                [$role, $existing['id']]
            );
        } else {
            $db->insert('tenant_user', [
                'tenant_id' => $this->id,
                'user_id' => $userId,
                'role' => $role,
            ]);
        }
    }

    public function removeMember(Connection $db, int $userId): void
    {
        if (!$this->id) {
            return;
        }

        $db->statement(
            "DELETE FROM tenant_user WHERE tenant_id = ? AND user_id = ?",
            [$this->id, $userId]
        );
    }

    public function updateRole(Connection $db, int $userId, string $role): void
    {
        $this->addMember($db, $userId, $role);
    }
}
