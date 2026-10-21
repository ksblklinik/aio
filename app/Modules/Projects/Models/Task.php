<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use Core\Database\Connection;

class Task
{
    public function __construct(
        public ?int $id = null,
        public int|string $tenantId = 0,
        public int $projectId = 0,
        public string $title = '',
        public int $isCompleted = 0,
        public ?string $createdAt = null
    ) {
    }

    public static function fromArray(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            tenantId: $row['tenant_id'] ?? 0,
            projectId: (int) ($row['project_id'] ?? 0),
            title: (string) ($row['title'] ?? ''),
            isCompleted: (int) ($row['is_completed'] ?? 0),
            createdAt: isset($row['created_at']) ? (string) $row['created_at'] : null
        );
    }

    /**
     * @return array<self>
     */
    public static function forProject(Connection $db, int $projectId, int $tenantId): array
    {
        $rows = $db->selectAll(
            "SELECT * FROM tasks WHERE project_id = ? AND tenant_id = ? ORDER BY id ASC",
            [$projectId, $tenantId]
        );

        return array_map([self::class, 'fromArray'], $rows);
    }

    public static function create(Connection $db, array $data): self
    {
        $id = $db->insert('tasks', [
            'tenant_id' => $data['tenant_id'] ?? 0,
            'project_id' => $data['project_id'] ?? 0,
            'title' => trim((string) ($data['title'] ?? '')),
            'is_completed' => (int) ($data['is_completed'] ?? 0),
        ]);

        return new self(
            id: (int) $id,
            tenantId: $data['tenant_id'] ?? 0,
            projectId: (int) ($data['project_id'] ?? 0),
            title: trim((string) ($data['title'] ?? '')),
            isCompleted: (int) ($data['is_completed'] ?? 0)
        );
    }

    public static function toggle(Connection $db, int $taskId, int $tenantId): bool
    {
        $row = $db->selectOne("SELECT is_completed FROM tasks WHERE id = ? AND tenant_id = ?", [$taskId, $tenantId]);
        if (!$row) {
            return false;
        }

        $newStatus = ((int) $row['is_completed']) === 1 ? 0 : 1;
        $db->statement(
            "UPDATE tasks SET is_completed = ? WHERE id = ? AND tenant_id = ?",
            [$newStatus, $taskId, $tenantId]
        );

        return true;
    }

    public static function delete(Connection $db, int $taskId, int $tenantId): bool
    {
        $db->statement("DELETE FROM tasks WHERE id = ? AND tenant_id = ?", [$taskId, $tenantId]);
        return true;
    }
}
