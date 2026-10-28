<?php

declare(strict_types=1);

namespace Core\Queue;

use Core\Database\Connection;
use Throwable;

class QueueManager
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * Masukkan pekerjaan baru ke dalam antrean (Queue).
     *
     * @param string $jobClass FQCN kelas yang mengimplementasikan JobInterface
     * @param array<string, mixed> $data Parameter data untuk job
     * @param int|null $tenantId
     * @param string $queue Nama antrean (default: 'default')
     * @param int $delaySeconds Penundaan ketersediaan pekerjaan (dalam detik)
     * @param int $maxTries Batas maksimal percobaan eksekusi jika gagal
     * @return int ID job yang dimasukkan
     */
    public function push(
        string $jobClass,
        array $data = [],
        ?int $tenantId = null,
        string $queue = 'default',
        int $delaySeconds = 0,
        int $maxTries = 3
    ): int {
        if ($tenantId === null) {
            $activeTenant = \Core\Database\TenantContext::getInstance()->getTenantId();
            if ($activeTenant !== null) {
                $tenantId = (int) $activeTenant;
            }
        }

        $now = time();
        $availableAt = date('Y-m-d H:i:s', $now + $delaySeconds);
        $createdAt = date('Y-m-d H:i:s', $now);

        $payload = json_encode([
            'job' => $jobClass,
            'data' => $data,
            'max_tries' => $maxTries,
        ], JSON_UNESCAPED_SLASHES);

        $id = $this->db->insert('jobs', [
            'tenant_id' => $tenantId,
            'queue' => $queue,
            'payload' => $payload,
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $availableAt,
            'created_at' => $createdAt,
        ]);

        return (int) $id;
    }

    /**
     * Ambil satu pekerjaan yang siap dieksekusi dari antrean secara aman dan transaksional.
     *
     * @param string $queue
     * @return Job|null
     */
    public function pop(string $queue = 'default'): ?Job
    {
        return $this->db->transaction(function () use ($queue) {
            $now = date('Y-m-d H:i:s');
            // Timeout reservasi 90 detik jika worker mati mendadak
            $staleReservation = date('Y-m-d H:i:s', time() - 90);

            $sql = "SELECT * FROM jobs 
                    WHERE queue = ? 
                      AND available_at <= ? 
                      AND (reserved_at IS NULL OR reserved_at <= ?)
                    ORDER BY id ASC 
                    LIMIT 1";

            $row = $this->db->selectOne($sql, [$queue, $now, $staleReservation]);
            if (!$row) {
                return null;
            }

            $jobId = (int) $row['id'];
            $newAttempts = ((int) $row['attempts']) + 1;

            $claimed = $this->db->affectingStatement(
                "UPDATE jobs SET reserved_at = ?, attempts = ? 
                 WHERE id = ? AND (reserved_at IS NULL OR reserved_at <= ?)",
                [$now, $newAttempts, $jobId, $staleReservation]
            );

            if ($claimed !== 1) {
                return null;
            }

            $payload = json_decode($row['payload'], true) ?: [];
            $jobClass = $payload['job'] ?? '';
            $data = $payload['data'] ?? [];
            $maxTries = (int) ($payload['max_tries'] ?? 3);

            return new Job(
                id: $jobId,
                tenantId: $row['tenant_id'] !== null ? (int) $row['tenant_id'] : null,
                queue: $row['queue'],
                jobClass: $jobClass,
                data: $data,
                attempts: $newAttempts,
                maxTries: $maxTries,
                rawPayload: $row['payload']
            );
        });
    }

    /**
     * Hapus pekerjaan yang telah berhasil dieksekusi dari antrean.
     */
    public function delete(Job|int $job): void
    {
        $id = $job instanceof Job ? $job->getId() : $job;
        $this->db->statement("DELETE FROM jobs WHERE id = ?", [$id]);
    }

    /**
     * Lepaskan pekerjaan kembali ke antrean dengan jeda waktu tertentu (delay).
     */
    public function release(Job|int $job, int $delaySeconds = 60): void
    {
        $id = $job instanceof Job ? $job->getId() : $job;
        $availableAt = date('Y-m-d H:i:s', time() + $delaySeconds);

        $this->db->statement(
            "UPDATE jobs SET reserved_at = NULL, available_at = ? WHERE id = ?",
            [$availableAt, $id]
        );
    }

    /**
     * Pindahkan pekerjaan yang gagal permanen ke tabel failed_jobs (Dead-Letter Queue).
     */
    public function fail(Job $job, Throwable $e): int
    {
        $now = date('Y-m-d H:i:s');
        $exceptionDetails = sprintf(
            "%s: %s in %s:%d\nStack trace:\n%s",
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );

        $failedId = $this->db->insert('failed_jobs', [
            'tenant_id' => $job->getTenantId(),
            'queue' => $job->getQueue(),
            'payload' => $job->getRawPayload(),
            'exception' => $exceptionDetails,
            'failed_at' => $now,
        ]);

        $this->delete($job);

        return (int) $failedId;
    }

    /**
     * Masukkan kembali pekerjaan yang gagal dari failed_jobs ke jobs utama.
     */
    public function retry(int $failedJobId): bool
    {
        $failedJob = $this->db->selectOne("SELECT * FROM failed_jobs WHERE id = ?", [$failedJobId]);
        if (!$failedJob) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->insert('jobs', [
            'tenant_id' => $failedJob['tenant_id'],
            'queue' => $failedJob['queue'],
            'payload' => $failedJob['payload'],
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $now,
            'created_at' => $now,
        ]);

        $this->db->statement("DELETE FROM failed_jobs WHERE id = ?", [$failedJobId]);

        return true;
    }

    /**
     * Hitung total pekerjaan yang masih mengantre di queue.
     */
    public function size(string $queue = 'default'): int
    {
        $row = $this->db->selectOne("SELECT COUNT(*) as count FROM jobs WHERE queue = ?", [$queue]);
        return $row ? (int) $row['count'] : 0;
    }

    /**
     * Hitung total pekerjaan yang gagal.
     */
    public function failedCount(): int
    {
        $row = $this->db->selectOne("SELECT COUNT(*) as count FROM failed_jobs");
        return $row ? (int) $row['count'] : 0;
    }

    /**
     * Ambil daftar pekerjaan yang gagal.
     *
     * @return array<array<string, mixed>>
     */
    public function getFailedJobs(int $limit = 50): array
    {
        return $this->db->selectAll(
            "SELECT * FROM failed_jobs ORDER BY id DESC LIMIT ?",
            [$limit]
        );
    }
}
