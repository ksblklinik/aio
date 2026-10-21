<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use Core\Database\Connection;
use Core\Database\TenantScope;

class Project
{
    public function __construct(
        public ?int $id = null,
        public int|string $tenantId = 0,
        public int|string $userId = 0,
        public string $name = '',
        public ?string $description = null,
        public string $status = 'in_progress',
        public ?string $createdAt = null
    ) {
    }

    public static function fromArray(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            tenantId: $row['tenant_id'] ?? 0,
            userId: $row['user_id'] ?? 0,
            name: (string) ($row['name'] ?? ''),
            description: isset($row['description']) ? (string) $row['description'] : null,
            status: (string) ($row['status'] ?? 'in_progress'),
            createdAt: isset($row['created_at']) ? (string) $row['created_at'] : null
        );
    }

    /**
     * @return array<self>
     */
    public static function allForTenant(TenantScope $scope): array
    {
        $rows = $scope->selectAll('projects', '', [], 'created_at DESC');
        return array_map([self::class, 'fromArray'], $rows);
    }

    public static function findForTenant(TenantScope $scope, int $id): ?self
    {
        $rows = $scope->selectWhere('projects', ['id' => $id]);
        return !empty($rows) ? self::fromArray($rows[0]) : null;
    }

    public static function createForTenant(TenantScope $scope, array $data): self
    {
        $id = $scope->insert('projects', [
            'user_id' => $data['user_id'] ?? 0,
            'name' => (string) ($data['name'] ?? ''),
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'in_progress',
        ]);

        return new self(
            id: (int) $id,
            tenantId: $scope->getContext()->getTenantId() ?? 0,
            userId: $data['user_id'] ?? 0,
            name: (string) ($data['name'] ?? ''),
            description: $data['description'] ?? null,
            status: $data['status'] ?? 'in_progress'
        );
    }

    public static function update(Connection $db, int $id, int|string $tenantId, array $data): bool
    {
        $fields = [];
        $values = [];

        foreach (['name', 'description', 'status'] as $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$key} = ?";
                $values[] = $data[$key];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $values[] = $id;
        $values[] = $tenantId;

        $sql = "UPDATE projects SET " . implode(', ', $fields) . " WHERE id = ? AND tenant_id = ?";
        $db->statement($sql, $values);
        return true;
    }

    public static function delete(Connection $db, int $id, int|string $tenantId): bool
    {
        $db->statement("DELETE FROM projects WHERE id = ? AND tenant_id = ?", [$id, $tenantId]);
        return true;
    }

    /**
     * @return array<Task>
     */
    public function getTasks(Connection $db): array
    {
        if (!$this->id) {
            return [];
        }

        return Task::forProject($db, $this->id, (int) $this->tenantId);
    }
}
