<?php

declare(strict_types=1);

namespace Core\Broadcasting;

use Core\Database\Connection;
use Core\Database\TenantContext;

class SseBroadcaster implements SseBroadcasterInterface
{
    public function __construct(
        protected Connection $db,
        protected ?TenantContext $tenantContext = null
    ) {}

    public function publish(string $channel, string $event, array $payload = [], ?string $tenantId = null): int
    {
        if ($tenantId === null && $this->tenantContext !== null) {
            $tenantId = $this->tenantContext->getTenantId();
        }

        $now = date('c');
        $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encodedPayload === false) {
            $encodedPayload = '{}';
        }

        $id = (int) $this->db->insert('sse_events', [
            'tenant_id' => $tenantId !== null ? (string)$tenantId : null,
            'channel' => $channel,
            'event' => $event,
            'payload' => $encodedPayload,
            'created_at' => $now,
        ]);

        return $id;
    }

    public function publishPublic(string $channel, string $event, array $payload = []): int
    {
        $now = date('c');
        $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encodedPayload === false) {
            $encodedPayload = '{}';
        }

        $id = (int) $this->db->insert('sse_events', [
            'tenant_id' => null,
            'channel' => $channel,
            'event' => $event,
            'payload' => $encodedPayload,
            'created_at' => $now,
        ]);

        return $id;
    }

    public function getEvents(string $channel, ?int $afterId = null, ?string $tenantId = null, int $limit = 50): array
    {
        if ($tenantId === null && $this->tenantContext !== null) {
            $tenantId = $this->tenantContext->getTenantId();
        }

        $sql = "SELECT id, tenant_id, channel, event, payload, created_at FROM sse_events WHERE channel = ?";
        $params = [$channel];

        if ($tenantId !== null) {
            $sql .= " AND (tenant_id = ? OR tenant_id IS NULL)";
            $params[] = (string)$tenantId;
        } else {
            $sql .= " AND tenant_id IS NULL";
        }

        if ($afterId !== null && $afterId > 0) {
            $sql .= " AND id > ?";
            $params[] = $afterId;
        }

        $boundedLimit = max(1, min($limit, 200));
        $sql .= " ORDER BY id ASC LIMIT {$boundedLimit}";

        $rows = $this->db->selectAll($sql, $params);

        return array_map(function (array $row): array {
            $decoded = json_decode((string)$row['payload'], true);
            return [
                'id' => (int)$row['id'],
                'tenant_id' => $row['tenant_id'] !== null ? (string)$row['tenant_id'] : null,
                'channel' => (string)$row['channel'],
                'event' => (string)$row['event'],
                'payload' => is_array($decoded) ? $decoded : [],
                'created_at' => (string)$row['created_at'],
            ];
        }, $rows);
    }

    public function getLatestEvent(string $channel, ?string $tenantId = null): ?array
    {
        if ($tenantId === null && $this->tenantContext !== null) {
            $tenantId = $this->tenantContext->getTenantId();
        }

        $sql = "SELECT id, tenant_id, channel, event, payload, created_at FROM sse_events WHERE channel = ?";
        $params = [$channel];

        if ($tenantId !== null) {
            $sql .= " AND (tenant_id = ? OR tenant_id IS NULL)";
            $params[] = (string)$tenantId;
        } else {
            $sql .= " AND tenant_id IS NULL";
        }

        $sql .= " ORDER BY id DESC LIMIT 1";

        $row = $this->db->selectOne($sql, $params);
        if ($row === null) {
            return null;
        }

        $decoded = json_decode((string)$row['payload'], true);
        return [
            'id' => (int)$row['id'],
            'tenant_id' => $row['tenant_id'] !== null ? (string)$row['tenant_id'] : null,
            'channel' => (string)$row['channel'],
            'event' => (string)$row['event'],
            'payload' => is_array($decoded) ? $decoded : [],
            'created_at' => (string)$row['created_at'],
        ];
    }

    public function prune(int $keepLastMinutes = 60): int
    {
        $cutoff = date('c', time() - max(1, $keepLastMinutes) * 60);
        $stmt = $this->db->getPdo()->prepare("DELETE FROM sse_events WHERE created_at < ?");
        $stmt->execute([$cutoff]);

        return $stmt->rowCount();
    }
}
