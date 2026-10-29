<?php

declare(strict_types=1);

namespace Core\Reconciliation;

use Core\Database\Connection;
use Core\Reconciliation\Auditors\AuditorInterface;
use Core\Reconciliation\Auditors\ClosedPeriodAuditor;
use Core\Reconciliation\Auditors\CryptographicSealAuditor;
use Core\Reconciliation\Auditors\DomainBoundaryAuditor;
use Core\Reconciliation\Auditors\FinanceBillingAuditor;
use Core\Reconciliation\Auditors\InventoryStockAuditor;
use Core\Reconciliation\Auditors\StateMonotonicityAuditor;
use Core\Reconciliation\Auditors\TariffSplitAuditor;
use Core\Reconciliation\Auditors\TenantReferentialAuditor;
use Throwable;

class ReconciliationManager implements ReconciliationManagerInterface
{
    /**
     * @var array<string, AuditorInterface>
     */
    private array $auditors = [];

    /**
     * @param array<AuditorInterface> $auditors
     */
    public function __construct(
        private Connection $db,
        array $auditors = []
    ) {
        if (empty($auditors)) {
            $this->registerAuditor(new FinanceBillingAuditor($this->db));
            $this->registerAuditor(new InventoryStockAuditor($this->db));
            $this->registerAuditor(new TariffSplitAuditor($this->db));
            $this->registerAuditor(new CryptographicSealAuditor($this->db));
            $this->registerAuditor(new TenantReferentialAuditor($this->db));
            $this->registerAuditor(new StateMonotonicityAuditor($this->db));
            $this->registerAuditor(new ClosedPeriodAuditor($this->db));
            $this->registerAuditor(new DomainBoundaryAuditor($this->db));
        } else {
            foreach ($auditors as $auditor) {
                $this->registerAuditor($auditor);
            }
        }
    }

    public function registerAuditor(AuditorInterface $auditor): void
    {
        $this->auditors[$auditor->getPillarKey()] = $auditor;
    }

    /**
     * @return array<string, AuditorInterface>
     */
    public function getAuditors(): array
    {
        return $this->auditors;
    }

    public function audit(?int $tenantId = null, ?string $pillar = null): IntegrityAuditReport
    {
        $auditorsToRun = $this->resolveAuditorsToRun($pillar);

        $pillarResults = [];
        $allDiscrepancies = [];
        $totalInspections = 0;
        $criticalCount = 0;

        foreach ($auditorsToRun as $key => $auditor) {
            $result = $auditor->audit($tenantId);
            $pillarResults[$key] = $result;
            $totalInspections += $result->inspectedCount;

            foreach ($result->discrepancies as $disc) {
                $allDiscrepancies[] = $disc;
                if ($disc->severity === 'critical') {
                    $criticalCount++;
                }
            }
        }

        $isHealthy = empty($allDiscrepancies);

        return new IntegrityAuditReport(
            isHealthy: $isHealthy,
            totalInspections: $totalInspections,
            discrepancyCount: count($allDiscrepancies),
            criticalCount: $criticalCount,
            pillars: $pillarResults,
            allDiscrepancies: $allDiscrepancies,
            auditedAt: date('Y-m-d H:i:s'),
            tenantId: $tenantId
        );
    }

    public function reconcile(?int $tenantId = null, ?string $pillar = null, bool $dryRun = false): ReconciliationResult
    {
        $auditorsToRun = $this->resolveAuditorsToRun($pillar);

        $lockKey = 'reconciliation:sync:' . ($tenantId ?? 'all');
        $lockAcquired = false;

        // Try locking if LockManager is registered in container/helpers
        if (!$dryRun && function_exists('lock')) {
            try {
                $lock = lock();
                $lockAcquired = $lock->acquire($lockKey, 60);
            } catch (Throwable) {
                $lockAcquired = false;
            }
        }

        try {
            $totalResolved = 0;
            $allMessages = [];
            $resolvedByPillar = [];
            $totalFoundDiscrepancies = 0;

            foreach ($auditorsToRun as $key => $auditor) {
                // 1. Audit first
                $auditResult = $auditor->audit($tenantId);
                $totalFoundDiscrepancies += $auditResult->discrepancyCount;

                // Log discrepancies to database if not in dry-run
                if (!$dryRun) {
                    $this->logDiscrepancies($auditResult->discrepancies);
                }

                // 2. Run reconciliation
                $syncResult = $auditor->reconcile($tenantId, $dryRun);
                $resolved = $syncResult['resolved'];
                $totalResolved += $resolved;
                $resolvedByPillar[$key] = $resolved;

                foreach ($syncResult['messages'] as $msg) {
                    $allMessages[] = sprintf('[%s] %s', strtoupper($key), $msg);
                }

                // Mark resolved in reconciliation_audit_logs if live run
                if (!$dryRun && $resolved > 0) {
                    $this->markAuditorLogsResolved($key, $tenantId);
                }
            }

            $unresolvedCount = max(0, $totalFoundDiscrepancies - $totalResolved);

            return new ReconciliationResult(
                success: true,
                isDryRun: $dryRun,
                resolvedCount: $totalResolved,
                unresolvedCount: $unresolvedCount,
                resolvedByPillar: $resolvedByPillar,
                messages: $allMessages,
                executedAt: date('Y-m-d H:i:s')
            );
        } finally {
            if ($lockAcquired && function_exists('lock')) {
                try {
                    lock()->release($lockKey);
                } catch (Throwable) {
                    // Ignore release error
                }
            }
        }
    }

    public function getPendingDiscrepancies(?int $tenantId = null): array
    {
        $sql = "
            SELECT id, tenant_id, pillar, severity, entity_type, entity_id,
                   description, expected_value, actual_value, resolved, created_at
            FROM reconciliation_audit_logs
            WHERE resolved = 0
        ";
        $params = [];
        if ($tenantId !== null) {
            $sql .= " AND tenant_id = ?";
            $params[] = $tenantId;
        }
        $sql .= " ORDER BY id DESC LIMIT 200";

        try {
            return $this->db->selectAll($sql, $params);
        } catch (Throwable) {
            return [];
        }
    }

    public function resolveDiscrepancy(int $logId, string $notes): bool
    {
        try {
            $updated = $this->db->statement(
                "UPDATE reconciliation_audit_logs 
                 SET resolved = 1, resolved_at = CURRENT_TIMESTAMP, resolution_notes = ? 
                 WHERE id = ?",
                [$notes, $logId]
            );
            return $updated > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, AuditorInterface>
     */
    private function resolveAuditorsToRun(?string $pillar): array
    {
        if ($pillar === null || trim($pillar) === '' || strtolower($pillar) === 'all') {
            return $this->auditors;
        }

        $pillarKey = strtolower(trim($pillar));
        if (!isset($this->auditors[$pillarKey])) {
            throw new \InvalidArgumentException(sprintf(
                "Pilar rekonsiliasi '%s' tidak valid. Pilihan tersedia: %s",
                $pillar,
                implode(', ', array_keys($this->auditors))
            ));
        }

        return [$pillarKey => $this->auditors[$pillarKey]];
    }

    /**
     * @param array<Discrepancy> $discrepancies
     */
    private function logDiscrepancies(array $discrepancies): void
    {
        $now = date('Y-m-d H:i:s');
        foreach ($discrepancies as $disc) {
            try {
                // Check if identical unresolved discrepancy already exists
                $existing = $this->db->selectOne("
                    SELECT id FROM reconciliation_audit_logs 
                    WHERE tenant_id = ? AND pillar = ? AND entity_type = ? AND entity_id = ? AND resolved = 0
                    LIMIT 1
                ", [$disc->tenantId, $disc->pillar, $disc->entityType, $disc->entityId]);

                if (!$existing) {
                    $this->db->statement("
                        INSERT INTO reconciliation_audit_logs (
                            tenant_id, pillar, severity, entity_type, entity_id,
                            description, expected_value, actual_value, resolved, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?)
                    ", [
                        $disc->tenantId,
                        $disc->pillar,
                        $disc->severity,
                        $disc->entityType,
                        $disc->entityId,
                        $disc->description,
                        $disc->expectedValue,
                        $disc->actualValue,
                        $now,
                    ]);
                }
            } catch (Throwable) {
                // Ignore DB logging error in restricted environments
            }
        }
    }

    private function markAuditorLogsResolved(string $pillar, ?int $tenantId = null): void
    {
        $sql = "
            UPDATE reconciliation_audit_logs 
            SET resolved = 1, resolved_at = CURRENT_TIMESTAMP, resolution_notes = 'Auto-reconciled by ReconciliationManager'
            WHERE pillar = ? AND resolved = 0
        ";
        $params = [$pillar];
        if ($tenantId !== null) {
            $sql .= " AND tenant_id = ?";
            $params[] = $tenantId;
        }

        try {
            $this->db->statement($sql, $params);
        } catch (Throwable) {
            // Ignore
        }
    }
}
