<?php

declare(strict_types=1);

namespace Core\Database;

use RuntimeException;

class TenantScope
{
    private string $trashedMode = 'without'; // 'without' | 'with' | 'only'

    /** @var array<string, bool> */
    private static array $hasDeletedAtCache = [];

    public function __construct(
        private TenantContext $context,
        private Connection $connection
    ) {
    }

    public function withTrashed(): self
    {
        $clone = clone $this;
        $clone->trashedMode = 'with';
        return $clone;
    }

    public function onlyTrashed(): self
    {
        $clone = clone $this;
        $clone->trashedMode = 'only';
        return $clone;
    }

    public function withoutTrashed(): self
    {
        $clone = clone $this;
        $clone->trashedMode = 'without';
        return $clone;
    }

    public function supportsSoftDeletes(string $table): bool
    {
        if (isset(self::$hasDeletedAtCache[$table])) {
            return self::$hasDeletedAtCache[$table];
        }

        try {
            $cols = $this->connection->selectAll("PRAGMA table_info({$table})");
            foreach ($cols as $col) {
                if (($col['name'] ?? '') === 'deleted_at') {
                    return self::$hasDeletedAtCache[$table] = true;
                }
            }
        } catch (\Throwable) {
            // Abaikan jika bukan SQLite atau PRAGMA gagal
        }

        return self::$hasDeletedAtCache[$table] = false;
    }

    private function applySoftDeleteFilter(string $table, string $additionalWhere): string
    {
        if (!$this->supportsSoftDeletes($table)) {
            return $additionalWhere;
        }

        if (str_contains($additionalWhere, 'deleted_at IS NULL') || str_contains($additionalWhere, 'deleted_at IS NOT NULL')) {
            return $additionalWhere;
        }

        $softFilter = match ($this->trashedMode) {
            'without' => 'deleted_at IS NULL',
            'only' => 'deleted_at IS NOT NULL',
            default => '',
        };

        if ($softFilter === '') {
            return $additionalWhere;
        }

        if ($additionalWhere !== '') {
            return "({$additionalWhere}) AND ({$softFilter})";
        }

        return $softFilter;
    }

    public function getContext(): TenantContext
    {
        return $this->context;
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    private function getRequiredTenantId(string $table): string|int
    {
        $tenantId = $this->context->getTenantId();
        if ($tenantId === null) {
            throw new RuntimeException("Operasi scoped [{$table}] membutuhkan konteks tenant yang aktif.");
        }

        return $tenantId;
    }

    public function selectAll(string $table, string $additionalWhere = '', array $params = [], string $orderBy = ''): array
    {
        $tenantId = $this->getRequiredTenantId($table);
        $additionalWhere = $this->applySoftDeleteFilter($table, $additionalWhere);

        $sql = "SELECT * FROM {$table} WHERE tenant_id = ?";
        $allParams = [$tenantId];

        if ($additionalWhere !== '') {
            $sql .= " AND ({$additionalWhere})";
            $allParams = array_merge($allParams, $params);
        }

        if ($orderBy !== '') {
            $sql .= " ORDER BY {$orderBy}";
        }

        return $this->connection->selectAll($sql, $allParams);
    }

    public function selectOne(string $table, string $additionalWhere = '', array $params = []): ?array
    {
        $tenantId = $this->getRequiredTenantId($table);
        $additionalWhere = $this->applySoftDeleteFilter($table, $additionalWhere);

        $sql = "SELECT * FROM {$table} WHERE tenant_id = ?";
        $allParams = [$tenantId];

        if ($additionalWhere !== '') {
            $sql .= " AND ({$additionalWhere})";
            $allParams = array_merge($allParams, $params);
        }

        $sql .= " LIMIT 1";

        return $this->connection->selectOne($sql, $allParams);
    }

    public function selectWhere(string $table, array $conditions): array
    {
        $clauses = [];
        $params = [];

        foreach ($conditions as $col => $val) {
            $clauses[] = "{$col} = ?";
            $params[] = $val;
        }

        $additionalWhere = implode(' AND ', $clauses);
        return $this->selectAll($table, $additionalWhere, $params);
    }

    public function insert(string $table, array $data): string
    {
        $tenantId = $this->getRequiredTenantId($table);

        $data['tenant_id'] = $tenantId;
        return $this->connection->insert($table, $data);
    }

    public function update(string $table, array $data, string $additionalWhere = '', array $params = []): int
    {
        $tenantId = $this->getRequiredTenantId($table);

        unset($data['tenant_id']); // Cegah manipulasi tenant_id secara sengaja

        $where = "tenant_id = ?";
        $whereParams = [$tenantId];

        if ($additionalWhere !== '') {
            $where .= " AND ({$additionalWhere})";
            $whereParams = array_merge($whereParams, $params);
        }

        return $this->connection->update($table, $data, $where, $whereParams);
    }

    public function delete(string $table, string $additionalWhere = '', array $params = []): int
    {
        $tenantId = $this->getRequiredTenantId($table);

        $where = "tenant_id = ?";
        $whereParams = [$tenantId];

        if ($additionalWhere !== '') {
            $where .= " AND ({$additionalWhere})";
            $whereParams = array_merge($whereParams, $params);
        }

        return $this->connection->delete($table, $where, $whereParams);
    }

    public function softDelete(string $table, string $additionalWhere = '', array $params = []): int
    {
        if (!$this->supportsSoftDeletes($table)) {
            throw new RuntimeException("Tabel [{$table}] tidak mendukung soft deletes (kolom deleted_at tidak ditemukan).");
        }

        return $this->update($table, ['deleted_at' => date('Y-m-d H:i:s')], $additionalWhere, $params);
    }

    public function restore(string $table, string $additionalWhere = '', array $params = []): int
    {
        if (!$this->supportsSoftDeletes($table)) {
            throw new RuntimeException("Tabel [{$table}] tidak mendukung soft deletes (kolom deleted_at tidak ditemukan).");
        }

        return $this->update($table, ['deleted_at' => null], $additionalWhere, $params);
    }

    public function forceDelete(string $table, string $additionalWhere = '', array $params = []): int
    {
        return $this->delete($table, $additionalWhere, $params);
    }

    public function count(string $table, string $additionalWhere = '', array $params = []): int
    {
        $tenantId = $this->getRequiredTenantId($table);
        $additionalWhere = $this->applySoftDeleteFilter($table, $additionalWhere);

        $sql = "SELECT COUNT(*) as aggregate_count FROM {$table} WHERE tenant_id = ?";
        $allParams = [$tenantId];

        if ($additionalWhere !== '') {
            $sql .= " AND ({$additionalWhere})";
            $allParams = array_merge($allParams, $params);
        }

        $row = $this->connection->selectOne($sql, $allParams);
        return (int) ($row['aggregate_count'] ?? 0);
    }

    public function exists(string $table, string $additionalWhere = '', array $params = []): bool
    {
        return $this->count($table, $additionalWhere, $params) > 0;
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, total: int, per_page: int, current_page: int, last_page: int}
     */
    public function paginate(string $table, int $perPage = 15, int $page = 1, string $additionalWhere = '', array $params = [], string $orderBy = ''): array
    {
        $tenantId = $this->getRequiredTenantId($table);
        $additionalWhere = $this->applySoftDeleteFilter($table, $additionalWhere);

        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $total = $this->count($table, $additionalWhere, $params);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT * FROM {$table} WHERE tenant_id = ?";
        $allParams = [$tenantId];

        if ($additionalWhere !== '') {
            $sql .= " AND ({$additionalWhere})";
            $allParams = array_merge($allParams, $params);
        }

        if ($orderBy !== '') {
            $sql .= " ORDER BY {$orderBy}";
        }

        $sql .= " LIMIT {$perPage} OFFSET {$offset}";

        $items = $this->connection->selectAll($sql, $allParams);

        $paginator = new \Core\Pagination\LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page
        );

        return $paginator->toArray();
    }
}
