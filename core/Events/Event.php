<?php

declare(strict_types=1);

namespace Core\Events;

abstract class Event
{
    public string $occurredAt;

    public function __construct(
        public ?int $tenantId = null,
        public ?int $userId = null
    ) {
        $this->occurredAt = date('Y-m-d H:i:s');
    }

    /**
     * Mengembalikan nama unik event (misal: 'crm.deal.created' atau 'project.created').
     */
    public function getEventName(): string
    {
        $class = (new \ReflectionClass($this))->getShortName();
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '.$0', $class));
    }

    public function getTenantId(): ?int
    {
        return $this->tenantId;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    /**
     * Konversi data event ke format payload terstandar untuk serialization dan webhook.
     */
    public function toPayload(): array
    {
        $data = get_object_vars($this);
        unset($data['occurredAt']);

        return [
            'event' => $this->getEventName(),
            'tenant_id' => $this->getTenantId(),
            'user_id' => $this->getUserId(),
            'occurred_at' => $this->occurredAt,
            'data' => $data,
        ];
    }
}
