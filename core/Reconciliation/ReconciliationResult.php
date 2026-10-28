<?php

declare(strict_types=1);

namespace Core\Reconciliation;

class ReconciliationResult
{
    /**
     * @param array<string, int> $resolvedByPillar
     * @param array<string> $messages
     */
    public function __construct(
        public readonly bool $success,
        public readonly bool $isDryRun,
        public readonly int $resolvedCount,
        public readonly int $unresolvedCount,
        public readonly array $resolvedByPillar,
        public readonly array $messages,
        public readonly string $executedAt
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'is_dry_run' => $this->isDryRun,
            'resolved_count' => $this->resolvedCount,
            'unresolved_count' => $this->unresolvedCount,
            'resolved_by_pillar' => $this->resolvedByPillar,
            'messages' => $this->messages,
            'executed_at' => $this->executedAt,
        ];
    }
}
