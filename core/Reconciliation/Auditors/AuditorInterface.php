<?php

declare(strict_types=1);

namespace Core\Reconciliation\Auditors;

use Core\Reconciliation\PillarAuditResult;

interface AuditorInterface
{
    public function getPillarKey(): string;
    public function getPillarName(): string;

    /**
     * Audit data integrity and balance for this pillar.
     */
    public function audit(?int $tenantId = null): PillarAuditResult;

    /**
     * Automatically reconcile desynchronized data for this pillar.
     *
     * @return array{resolved: int, messages: array<string>}
     */
    public function reconcile(?int $tenantId = null, bool $dryRun = false): array;
}
