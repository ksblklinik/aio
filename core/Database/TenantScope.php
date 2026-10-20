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

    public function selectAll(string $table, string $additionalWhere = '', array $params = []): array
    {
        $tenantId = $this->context->getTenantId();
        if ($tenantId === null) {
            throw new RuntimeException("Operasi scoped [{$table}] membutuhkan konteks tenant yang aktif.");
        }

        $sql = "SELECT * FROM {$table} WHERE tenant_id = ?";
        $allParams = [$tenantId];

        if ($additionalWhere !== '') {
            $sql .= " AND ({$additionalWhere})";
            $allParams = array_merge($allParams, $params);
        }

        return $this->connection->selectAll($sql, $allParams);
    }

    public function selectOne(string $table, string $additionalWhere = '', array $params = []): ?array
    {
        $tenantId = $this->context->getTenantId();
        if ($tenantId === null) {
            throw new RuntimeException("Operasi scoped [{$table}] membutuhkan konteks tenant yang aktif.");
        }

        $sql = "SELECT * FROM {$table} WHERE tenant_id = ?";
        $allParams = [$tenantId];

        if ($additionalWhere !== '') {
            $sql .= " AND ({$additionalWhere})";
            $allParams = array_merge($allParams, $params);
        }

        $sql .= " LIMIT 1";

        return $this->connection->selectOne($sql, $allParams);
    }

    public function insert(string $table, array $data): string
    {
        $tenantId = $this->context->getTenantId();
        if ($tenantId === null) {
            throw new RuntimeException("Operasi scoped [{$table}] membutuhkan konteks tenant yang aktif.");
        }

        $data['tenant_id'] = $tenantId;
        return $this->connection->insert($table, $data);
    }
}
