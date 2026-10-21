<?php

declare(strict_types=1);

namespace Core\Queue;

class Job
{
    /**
     * @param int $id
     * @param int|null $tenantId
     * @param string $queue
     * @param string $jobClass
     * @param array<string, mixed> $data
     * @param int $attempts
     * @param int $maxTries
     * @param string $rawPayload
     */
    public function __construct(
        private int $id,
        private ?int $tenantId,
        private string $queue,
        private string $jobClass,
        private array $data,
        private int $attempts,
        private int $maxTries,
        private string $rawPayload
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getTenantId(): ?int
    {
        return $this->tenantId;
    }

    public function getQueue(): string
    {
        return $this->queue;
    }

    public function getJobClass(): string
    {
        return $this->jobClass;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getMaxTries(): int
    {
        return $this->maxTries;
    }

    public function getRawPayload(): string
    {
        return $this->rawPayload;
    }
}
