<?php

declare(strict_types=1);

namespace Core\Reconciliation;

interface ReconciliationManagerInterface
{
    /**
     * Perform cross-module mathematical balance audit across 5 universal pillars.
     *
     * @param int|null $tenantId Optional tenant scope. If null, audits all tenants.
     * @param string|null $pillar Optional specific pillar ('finance', 'inventory', 'tariff', 'seal', 'referential').
     */
    public function audit(?int $tenantId = null, ?string $pillar = null): IntegrityAuditReport;

    /**
     * Execute automated reconciliation and self-healing synchronization.
     *
     * @param int|null $tenantId
     * @param string|null $pillar
     * @param bool $dryRun If true, previews changes without writing to database.
     */
    public function reconcile(?int $tenantId = null, ?string $pillar = null, bool $dryRun = false): ReconciliationResult;

    /**
     * Retrieve unresolved audit discrepancies recorded in reconciliation_audit_logs.
     *
     * @return array<array<string, mixed>>
     */
    public function getPendingDiscrepancies(?int $tenantId = null): array;

    /**
     * Mark an audit discrepancy as manually resolved with operator notes.
     */
    public function resolveDiscrepancy(int $logId, string $notes): bool;
}
