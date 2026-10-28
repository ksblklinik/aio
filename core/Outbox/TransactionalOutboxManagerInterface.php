<?php

declare(strict_types=1);

namespace Core\Outbox;

interface TransactionalOutboxManagerInterface
{
    /**
     * Record an event in the outbox table within the current database transaction.
     *
     * @param string $eventName FQCN or string name of the event
     * @param array<string, mixed> $payload Event data payload
     * @param int|null $tenantId Tenant scope
     * @param string|null $targetChannel Target SSE broadcast channel or webhook category
     * @param string|null $eventId Deterministic UUID or idempotency key. If null, generates unique ID.
     */
    public function record(
        string $eventName,
        array $payload = [],
        ?int $tenantId = null,
        ?string $targetChannel = null,
        ?string $eventId = null
    ): OutboxMessage;

    /**
     * Record an Event object into the outbox.
     */
    public function recordEvent(object $event, ?string $targetChannel = null): OutboxMessage;

    /**
     * Relay pending outbox events to downstream consumers (SSE, Webhooks, Event Dispatcher).
     * Protected by atomic concurrency lock to allow safe parallel worker execution.
     *
     * @return array{published: int, failed: int, errors: array<string>}
     */
    public function relay(int $batchSize = 50): array;

    /**
     * Drain all pending outbox events until none remain.
     */
    public function drainAll(int $maxBatches = 20): int;

    /**
     * Get count of pending outbox events.
     */
    public function getPendingCount(?int $tenantId = null): int;

    /**
     * Get outbox metrics and health stats.
     *
     * @return array<string, mixed>
     */
    public function getStats(?int $tenantId = null): array;

    /**
     * Delete old published outbox events.
     *
     * @param int $keepDays Keep events younger than N days
     * @return int Number of deleted records
     */
    public function prune(int $keepDays = 7): int;
}
