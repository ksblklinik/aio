<?php

declare(strict_types=1);

namespace Core\Lock\Drivers;

use Core\Database\Connection;
use Throwable;

class DatabaseLockDriver implements LockDriverInterface
{
    public function __construct(
        protected Connection $db
    ) {}

    public function acquire(string $key, string $owner, int $ttlSeconds, int $waitTimeoutMs = 0): bool
    {
        $ttlSeconds = max(1, $ttlSeconds);
        $startTime = microtime(true) * 1000;

        do {
            if ($this->tryAcquire($key, $owner, $ttlSeconds)) {
                return true;
            }

            if ($waitTimeoutMs <= 0) {
                return false;
            }

            // Sleep 20ms - 30ms secara adaptif sebelum mencoba kembali
            usleep(25000);
        } while ((microtime(true) * 1000 - $startTime) < $waitTimeoutMs);

        return false;
    }

    protected function tryAcquire(string $key, string $owner, int $ttlSeconds): bool
    {
        $now = (int) (microtime(true) * 1000);
        $expiresAt = $now + ($ttlSeconds * 1000);
        $createdAt = date('c');

        // 1. Coba INSERT atomik (jika belum ada lock untuk key ini)
        try {
            $stmt = $this->db->getPdo()->prepare(
                "INSERT INTO locks (\"key\", \"owner\", \"expires_at\", \"created_at\") VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([$key, $owner, $expiresAt, $createdAt]);
            return true;
        } catch (Throwable) {
            // Primary key duplicate: ada lock yang sedang tersimpan
        }

        // 2. Jika key sudah ada, cek apakah lock tersebut sudah kedaluwarsa atau dipegang owner yang sama
        try {
            $stmt = $this->db->getPdo()->prepare(
                "UPDATE locks 
                 SET \"owner\" = ?, \"expires_at\" = ?, \"created_at\" = ? 
                 WHERE \"key\" = ? AND (\"expires_at\" <= ? OR \"owner\" = ?)"
            );
            $stmt->execute([$owner, $expiresAt, $createdAt, $key, $now, $owner]);
            return $stmt->rowCount() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    public function release(string $key, string $owner): bool
    {
        try {
            $stmt = $this->db->getPdo()->prepare(
                "DELETE FROM locks WHERE \"key\" = ? AND \"owner\" = ?"
            );
            $stmt->execute([$key, $owner]);
            return $stmt->rowCount() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    public function forceRelease(string $key): bool
    {
        try {
            $stmt = $this->db->getPdo()->prepare("DELETE FROM locks WHERE \"key\" = ?");
            $stmt->execute([$key]);
            return $stmt->rowCount() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    public function isLocked(string $key): bool
    {
        $now = (int) (microtime(true) * 1000);
        $row = $this->db->selectOne(
            "SELECT \"key\" FROM locks WHERE \"key\" = ? AND \"expires_at\" > ?",
            [$key, $now]
        );
        return $row !== null;
    }

    public function getOwner(string $key): ?string
    {
        $now = (int) (microtime(true) * 1000);
        $row = $this->db->selectOne(
            "SELECT \"owner\" FROM locks WHERE \"key\" = ? AND \"expires_at\" > ?",
            [$key, $now]
        );
        return $row['owner'] ?? null;
    }

    public function pruneExpired(): int
    {
        $now = (int) (microtime(true) * 1000);
        try {
            $stmt = $this->db->getPdo()->prepare("DELETE FROM locks WHERE \"expires_at\" <= ?");
            $stmt->execute([$now]);
            return $stmt->rowCount();
        } catch (Throwable) {
            return 0;
        }
    }

    public function listActiveLocks(): array
    {
        $now = (int) (microtime(true) * 1000);
        $rows = $this->db->selectAll(
            "SELECT \"key\", \"owner\", \"expires_at\", \"created_at\" FROM locks WHERE \"expires_at\" > ? ORDER BY \"created_at\" DESC",
            [$now]
        );

        return array_map(function (array $row) use ($now): array {
            $remaining = max(0, (int) round(((int)$row['expires_at'] - $now) / 1000));
            return [
                'key' => (string)$row['key'],
                'owner' => (string)$row['owner'],
                'expires_at' => (int)$row['expires_at'],
                'created_at' => (string)$row['created_at'],
                'remaining_seconds' => $remaining,
            ];
        }, $rows);
    }
}
