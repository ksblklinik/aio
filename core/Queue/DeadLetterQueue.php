<?php

declare(strict_types=1);

namespace Core\Queue;

use Core\Database\Connection;
use Throwable;

class DeadLetterQueue implements DeadLetterQueueInterface
{
    public function __construct(private Connection $db)
    {
    }

    public function list(?string $queue = null, int $limit = 50, int $offset = 0): array
    {
        $sql = "SELECT * FROM failed_jobs";
        $params = [];

        if ($queue !== null && $queue !== '') {
            $sql .= " WHERE queue = ?";
            $params[] = $queue;
        }

        $sql .= " ORDER BY id DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        try {
            return $this->db->selectAll($sql, $params);
        } catch (Throwable) {
            return [];
        }
    }

    public function count(?string $queue = null): int
    {
        $sql = "SELECT COUNT(*) as count FROM failed_jobs";
        $params = [];

        if ($queue !== null && $queue !== '') {
            $sql .= " WHERE queue = ?";
            $params[] = $queue;
        }

        try {
            $row = $this->db->selectOne($sql, $params);
            return $row ? (int) $row['count'] : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    public function retry(int $failedJobId): bool
    {
        $row = $this->db->selectOne("SELECT * FROM failed_jobs WHERE id = ?", [$failedJobId]);
        if (!$row) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $payload = json_decode((string) $row['payload'], true) ?: [];

        // Reset attempts count in payload or allow incremental retry
        $payload['retry_count'] = ((int) ($payload['retry_count'] ?? 0)) + 1;
        $newPayloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $inserted = $this->db->statement("
            INSERT INTO jobs (tenant_id, queue, payload, attempts, reserved_at, available_at, created_at)
            VALUES (?, ?, ?, 0, NULL, ?, ?)
        ", [
            $row['tenant_id'],
            $row['queue'],
            $newPayloadJson,
            $now,
            $now,
        ]);

        if ($inserted) {
            $this->db->statement("DELETE FROM failed_jobs WHERE id = ?", [$failedJobId]);
            return true;
        }

        return false;
    }

    public function retryBatch(array $failedJobIds): array
    {
        $retried = 0;
        $failed = 0;

        foreach ($failedJobIds as $id) {
            if ($this->retry((int) $id)) {
                $retried++;
            } else {
                $failed++;
            }
        }

        return ['retried' => $retried, 'failed' => $failed];
    }

    public function retryAll(?string $queue = null): int
    {
        $sql = "SELECT id FROM failed_jobs";
        $params = [];

        if ($queue !== null && $queue !== '') {
            $sql .= " WHERE queue = ?";
            $params[] = $queue;
        }

        $rows = $this->db->selectAll($sql, $params);
        $retried = 0;

        foreach ($rows as $r) {
            if ($this->retry((int) $r['id'])) {
                $retried++;
            }
        }

        return $retried;
    }

    public function purge(?string $queue = null, ?int $beforeDays = null): int
    {
        $sql = "DELETE FROM failed_jobs WHERE 1=1";
        $params = [];

        if ($queue !== null && $queue !== '') {
            $sql .= " AND queue = ?";
            $params[] = $queue;
        }

        if ($beforeDays !== null && $beforeDays >= 0) {
            $thresholdDate = date('Y-m-d H:i:s', strtotime("-{$beforeDays} days"));
            $sql .= " AND failed_at <= ?";
            $params[] = $thresholdDate;
        }

        return $this->db->affectingStatement($sql, $params);
    }

    public function diagnostics(): array
    {
        try {
            $total = $this->count();

            // By Queue
            $byQueue = [];
            $queueRows = $this->db->selectAll("SELECT queue, COUNT(*) as cnt FROM failed_jobs GROUP BY queue");
            foreach ($queueRows as $qr) {
                $byQueue[$qr['queue']] = (int) $qr['cnt'];
            }

            // Oldest and newest
            $timeRow = $this->db->selectOne("SELECT MIN(failed_at) as oldest, MAX(failed_at) as newest FROM failed_jobs");

            // Top exceptions
            $topExceptions = [];
            $exRows = $this->db->selectAll("SELECT exception FROM failed_jobs ORDER BY id DESC LIMIT 100");
            foreach ($exRows as $er) {
                $firstLine = strtok((string) $er['exception'], "\n") ?: 'UnknownException';
                $exClass = explode(':', $firstLine)[0] ?? 'UnknownException';
                $topExceptions[$exClass] = ($topExceptions[$exClass] ?? 0) + 1;
            }
            arsort($topExceptions);

            return [
                'total_failed' => $total,
                'by_queue' => $byQueue,
                'oldest_failed_at' => $timeRow['oldest'] ?? null,
                'newest_failed_at' => $timeRow['newest'] ?? null,
                'top_exceptions' => array_slice($topExceptions, 0, 5),
            ];
        } catch (Throwable $e) {
            return [
                'total_failed' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }
}
