<?php

declare(strict_types=1);

namespace Core\Reconciliation;

class IntegrityAuditReport
{
    /**
     * @param array<string, PillarAuditResult> $pillars
     * @param array<Discrepancy> $allDiscrepancies
     */
    public function __construct(
        public readonly bool $isHealthy,
        public readonly int $totalInspections,
        public readonly int $discrepancyCount,
        public readonly int $criticalCount,
        public readonly array $pillars,
        public readonly array $allDiscrepancies,
        public readonly string $auditedAt,
        public readonly ?int $tenantId = null
    ) {
    }

    public function hasDiscrepancies(): bool
    {
        return $this->discrepancyCount > 0;
    }

    public function getSummary(): string
    {
        if ($this->isHealthy) {
            return sprintf(
                'Cross-module integrity audit is 100%% BALANCED. %d checks performed across 5 pillars. Zero drifts detected.',
                $this->totalInspections
            );
        }

        return sprintf(
            'Cross-module integrity audit found %d discrepancies (%d critical) across %d inspected entities.',
            $this->discrepancyCount,
            $this->criticalCount,
            $this->totalInspections
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $pillarsArr = [];
        foreach ($this->pillars as $key => $pillar) {
            $pillarsArr[$key] = $pillar->toArray();
        }

        return [
            'is_healthy' => $this->isHealthy,
            'total_inspections' => $this->totalInspections,
            'discrepancy_count' => $this->discrepancyCount,
            'critical_count' => $this->criticalCount,
            'pillars' => $pillarsArr,
            'discrepancies' => array_map(fn(Discrepancy $d) => $d->toArray(), $this->allDiscrepancies),
            'audited_at' => $this->auditedAt,
            'tenant_id' => $this->tenantId,
        ];
    }
}
