<?php

declare(strict_types=1);

namespace Core\Queue;

interface DeadLetterQueueInterface
{
    /**
     * List failed jobs with pagination.
     *
     * @return array<array<string, mixed>>
     */
    public function list(?string $queue = null, int $limit = 50, int $offset = 0): array;

    /**
     * Count failed jobs.
     */
    public function count(?string $queue = null): int;

    /**
     * Retry a single failed job by re-queuing it into jobs table.
     */
    public function retry(int $failedJobId): bool;

    /**
     * Retry multiple failed jobs by their IDs.
     *
     * @param array<int> $failedJobIds
     * @return array{retried: int, failed: int}
     */
    public function retryBatch(array $failedJobIds): array;

    /**
     * Retry all failed jobs (optionally filtered by queue).
     */
    public function retryAll(?string $queue = null): int;

    /**
     * Purge old failed jobs permanently.
     *
     * @param string|null $queue
     * @param int|null $beforeDays Purge failed jobs older than N days. If null, purges all matching queue.
     * @return int Number of deleted jobs
     */
    public function purge(?string $queue = null, ?int $beforeDays = null): int;

    /**
     * Get dead-letter queue diagnostics and anomaly metrics.
     *
     * @return array<string, mixed>
     */
    public function diagnostics(): array;
}
