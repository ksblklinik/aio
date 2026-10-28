<?php

declare(strict_types=1);

namespace Core\Outbox;

class OutboxMessage
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly int $id,
        public readonly ?int $tenantId,
        public readonly string $eventId,
        public readonly string $eventName,
        public readonly array $payload,
        public readonly ?string $targetChannel = null,
        public readonly string $status = 'pending',
        public readonly int $attempts = 0,
        public readonly int $maxAttempts = 5,
        public readonly ?string $lastError = null,
        public readonly ?string $publishedAt = null,
        public readonly ?string $createdAt = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'event_id' => $this->eventId,
            'event_name' => $this->eventName,
            'payload' => $this->payload,
            'target_channel' => $this->targetChannel,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'max_attempts' => $this->maxAttempts,
            'last_error' => $this->lastError,
            'published_at' => $this->publishedAt,
            'created_at' => $this->createdAt,
        ];
    }
}
