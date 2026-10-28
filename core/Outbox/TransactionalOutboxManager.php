<?php

declare(strict_types=1);

namespace Core\Outbox;

use Core\Broadcasting\SseBroadcasterInterface;
use Core\Database\Connection;
use Core\Database\TenantContext;
use Core\Events\Event;
use Core\Events\EventDispatcher;
use Core\Webhook\WebhookDispatcher;
use Throwable;

class TransactionalOutboxManager implements TransactionalOutboxManagerInterface
{
    public function __construct(
        private Connection $db,
        private ?EventDispatcher $eventDispatcher = null,
        private ?SseBroadcasterInterface $sseBroadcaster = null,
        private ?WebhookDispatcher $webhookDispatcher = null
    ) {
    }

    public function record(
        string $eventName,
        array $payload = [],
        ?int $tenantId = null,
        ?string $targetChannel = null,
        ?string $eventId = null
    ): OutboxMessage {
        if ($tenantId === null) {
            $ctxId = TenantContext::getInstance()->getTenantId();
            if ($ctxId !== null) {
                $tenantId = (int) $ctxId;
            }
        }

        $eventId = $eventId ?? ('evt_' . bin2hex(random_bytes(16)));
        $now = date('Y-m-d H:i:s');
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $this->db->statement("
            INSERT INTO outbox_events (
                tenant_id, event_id, event_name, payload, target_channel,
                status, attempts, max_attempts, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, 'pending', 0, 5, ?, ?)
        ", [
            $tenantId,
            $eventId,
            $eventName,
            $jsonPayload,
            $targetChannel,
            $now,
            $now,
        ]);

        $id = (int) $this->db->lastInsertId();

        return new OutboxMessage(
            id: $id,
            tenantId: $tenantId,
            eventId: $eventId,
            eventName: $eventName,
            payload: $payload,
            targetChannel: $targetChannel,
            status: 'pending',
            attempts: 0,
            maxAttempts: 5,
            lastError: null,
            publishedAt: null,
            createdAt: $now
        );
    }

    public function recordEvent(object $event, ?string $targetChannel = null): OutboxMessage
    {
        if ($event instanceof Event) {
            return $this->record(
                eventName: $event->getEventName(),
                payload: $event->toPayload(),
                tenantId: $event->getTenantId(),
                targetChannel: $targetChannel,
                eventId: method_exists($event, 'getEventId') ? $event->getEventId() : null
            );
        }

        $eventName = get_class($event);
        $payload = method_exists($event, 'toArray') 
            ? $event->toArray() 
            : (method_exists($event, 'toPayload') ? $event->toPayload() : (array) $event);

        return $this->record(
            eventName: $eventName,
            payload: $payload,
            tenantId: null,
            targetChannel: $targetChannel
        );
    }

    public function relay(int $batchSize = 50): array
    {
        $lockKey = 'outbox:relay';
        $lockAcquired = false;

        if (function_exists('lock')) {
            try {
                $lockAcquired = lock()->acquire($lockKey, 60);
            } catch (Throwable) {
                $lockAcquired = false;
            }
        }

        $published = 0;
        $failed = 0;
        $errors = [];

        try {
            $rows = $this->db->selectAll("
                SELECT * FROM outbox_events 
                WHERE status = 'pending' AND attempts < max_attempts 
                ORDER BY id ASC 
                LIMIT ?
            ", [$batchSize]);

            $now = date('Y-m-d H:i:s');

            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $attempts = ((int) $row['attempts']) + 1;
                $maxAttempts = (int) $row['max_attempts'];
                $tenantId = $row['tenant_id'] !== null ? (int) $row['tenant_id'] : null;
                $eventName = (string) $row['event_name'];
                $channel = $row['target_channel'] ? (string) $row['target_channel'] : null;
                $payload = json_decode((string) $row['payload'], true) ?: [];

                try {
                    // 1. Dispatch to SSE Broadcaster if channel specified or default tenant channel
                    if ($this->sseBroadcaster !== null && ($channel !== null || $tenantId !== null)) {
                        $targetChannel = $channel ?? ('tenant.' . $tenantId);
                        $this->sseBroadcaster->publish(
                            $targetChannel,
                            $eventName,
                            $payload,
                            $tenantId ? (string) $tenantId : null
                        );
                    }

                    // 2. Dispatch to Outbound Webhook if tenantId present
                    if ($this->webhookDispatcher !== null && $tenantId !== null) {
                        $this->webhookDispatcher->dispatch(
                            $tenantId,
                            $eventName,
                            $payload
                        );
                    }

                    // 3. Mark as successfully published
                    $this->db->statement("
                        UPDATE outbox_events 
                        SET status = 'published', attempts = ?, published_at = ?, last_error = NULL, updated_at = ?
                        WHERE id = ?
                    ", [$attempts, $now, $now, $id]);

                    $published++;
                } catch (Throwable $e) {
                    $failed++;
                    $errMsg = mb_substr($e->getMessage(), 0, 500);
                    $errors[] = sprintf("Event #%d (%s): %s", $id, $eventName, $errMsg);

                    $newStatus = ($attempts >= $maxAttempts) ? 'failed' : 'pending';

                    $this->db->statement("
                        UPDATE outbox_events 
                        SET status = ?, attempts = ?, last_error = ?, updated_at = ?
                        WHERE id = ?
                    ", [$newStatus, $attempts, $errMsg, $now, $id]);
                }
            }

            return [
                'published' => $published,
                'failed' => $failed,
                'errors' => $errors,
            ];
        } finally {
            if ($lockAcquired && function_exists('lock')) {
                try {
                    lock()->release($lockKey);
                } catch (Throwable) {
                    // Ignore release failure
                }
            }
        }
    }

    public function drainAll(int $maxBatches = 20): int
    {
        $totalPublished = 0;
        $batch = 0;

        while ($batch < $maxBatches) {
            $res = $this->relay(50);
            $totalPublished += $res['published'];

            if ($res['published'] === 0) {
                break;
            }
            $batch++;
        }

        return $totalPublished;
    }

    public function getPendingCount(?int $tenantId = null): int
    {
        $sql = "SELECT COUNT(*) as cnt FROM outbox_events WHERE status = 'pending'";
        $params = [];

        if ($tenantId !== null) {
            $sql .= " AND tenant_id = ?";
            $params[] = $tenantId;
        }

        try {
            return (int) ($this->db->selectOne($sql, $params)['cnt'] ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    public function getStats(?int $tenantId = null): array
    {
        $sql = "SELECT status, COUNT(*) as cnt FROM outbox_events";
        $params = [];

        if ($tenantId !== null) {
            $sql .= " WHERE tenant_id = ?";
            $params[] = $tenantId;
        }

        $sql .= " GROUP BY status";

        try {
            $rows = $this->db->selectAll($sql, $params);
            $stats = ['pending' => 0, 'published' => 0, 'failed' => 0, 'total' => 0];

            foreach ($rows as $r) {
                $status = (string) $r['status'];
                $count = (int) $r['cnt'];
                $stats[$status] = $count;
                $stats['total'] += $count;
            }

            return $stats;
        } catch (Throwable) {
            return ['pending' => 0, 'published' => 0, 'failed' => 0, 'total' => 0];
        }
    }

    public function prune(int $keepDays = 7): int
    {
        $threshold = date('Y-m-d H:i:s', strtotime("-{$keepDays} days"));

        try {
            return $this->db->affectingStatement(
                "DELETE FROM outbox_events WHERE status = 'published' AND published_at <= ?",
                [$threshold]
            );
        } catch (Throwable) {
            return 0;
        }
    }
}
