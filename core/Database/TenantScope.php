<?php

declare(strict_types=1);

namespace Core\Database;

use RuntimeException;

class TenantScope
{
    public function __construct(
        private TenantContext $context,
        private Connection $connection
    ) {
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

    public function count(string $table, string $additionalWhere = '', array $params = []): int
    {
        $tenantId = $this->getRequiredTenantId($table);

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
