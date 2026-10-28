<?php

declare(strict_types=1);

namespace Core\Reconciliation;

class PillarAuditResult
{
    /**
     * @param array<Discrepancy> $discrepancies
     * @param array<string, mixed> $metrics
     */
    public function __construct(
        public readonly string $pillar,
        public readonly string $name,
        public readonly bool $isBalanced,
        public readonly int $inspectedCount,
        public readonly int $discrepancyCount,
        public readonly array $discrepancies = [],
        public readonly array $metrics = []
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pillar' => $this->pillar,
            'name' => $this->name,
            'is_balanced' => $this->isBalanced,
            'inspected_count' => $this->inspectedCount,
            'discrepancy_count' => $this->discrepancyCount,
            'discrepancies' => array_map(fn(Discrepancy $d) => $d->toArray(), $this->discrepancies),
            'metrics' => $this->metrics,
        ];
    }
}
