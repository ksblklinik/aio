<?php

declare(strict_types=1);

namespace Core\Reconciliation;

class Discrepancy
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $pillar,
        public readonly string $severity, // 'info', 'warning', 'critical'
        public readonly string $entityType,
        public readonly string $entityId,
        public readonly string $description,
        public readonly ?string $expectedValue = null,
        public readonly ?string $actualValue = null,
        public readonly bool $autoResolvable = false
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'pillar' => $this->pillar,
            'severity' => $this->severity,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'description' => $this->description,
            'expected_value' => $this->expectedValue,
            'actual_value' => $this->actualValue,
            'auto_resolvable' => $this->autoResolvable,
        ];
    }
}
