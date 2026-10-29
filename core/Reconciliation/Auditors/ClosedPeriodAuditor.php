<?php

declare(strict_types=1);

namespace Core\Reconciliation\Auditors;

use Core\Database\Connection;
use Core\Reconciliation\Discrepancy;
use Core\Reconciliation\PillarAuditResult;
use Throwable;

class ClosedPeriodAuditor implements AuditorInterface
{
    public function __construct(private Connection $db)
    {
    }

    public function getPillarKey(): string
    {
        return 'closed_period';
    }

    public function getPillarName(): string
    {
        return 'Fiscal & Accounting Closed-Period Immutability';
    }

    public function audit(?int $tenantId = null): PillarAuditResult
    {
        $discrepancies = [];
        $inspectedCount = 0;

        // 1. Fetch closed_period_until setting per tenant
        $settingsSql = "SELECT tenant_id, value, updated_at FROM tenant_settings WHERE module = 'finance' AND key = 'closed_period_until'";
        $params = [];
        if ($tenantId !== null) {
            $settingsSql .= " AND tenant_id = ?";
            $params[] = $tenantId;
        }

        try {
            $closedSettings = $this->db->selectAll($settingsSql, $params);
            $closedMap = [];
            foreach ($closedSettings as $cs) {
                if (!empty($cs['value'])) {
                    $closedMap[(int) $cs['tenant_id']] = [
                        'closed_until' => (string) $cs['value'],
                        'closed_at' => (string) $cs['updated_at'],
                    ];
                }
            }

            // If we have closed periods, inspect transactions created after close date
            foreach ($closedMap as $tId => $info) {
                $closedUntil = $info['closed_until'];
                $closedAt = $info['closed_at'];

                // Check financial_transactions
                $txSql = "
                    SELECT id, transaction_number, transaction_date, created_at, notes
                    FROM financial_transactions
                    WHERE tenant_id = ? 
                      AND transaction_date <= ?
                      AND created_at > ?
                ";
                $violations = $this->db->selectAll($txSql, [$tId, $closedUntil, $closedAt]);
                foreach ($violations as $v) {
                    $inspectedCount++;
                    $notes = (string) ($v['notes'] ?? '');
                    // If not explicitly flagged as prior_period_adjustment
                    if (!str_contains(strtolower($notes), 'prior_period_adjustment')) {
                        $discrepancies[] = new Discrepancy(
                            tenantId: $tId,
                            pillar: $this->getPillarKey(),
                            severity: 'critical',
                            entityType: 'financial_transaction',
                            entityId: (string) $v['id'],
                            description: "Transaksi {$v['transaction_number']} bertanggal {$v['transaction_date']} disisipkan ke periode tutup buku (s/d {$closedUntil}) tanpa flag penyesuaian.",
                            expectedValue: 'transacted_at > ' . $closedUntil,
                            actualValue: (string) $v['transaction_date'],
                            autoResolvable: false
                        );
                    }
                }
            }
        } catch (\Throwable $e) {
            \Core\Log\Logger::getInstance()->warning(static::class . ": " . $e->getMessage());
        }

        // 2. Audit temporal sanity (extreme past or future dates)
        try {
            $dateSanitySql = "
                SELECT id, tenant_id, transaction_number, transaction_date
                FROM financial_transactions
                WHERE (transaction_date > '2099-12-31' OR transaction_date < '2000-01-01')
            ";
            $dateSanityParams = [];
            if ($tenantId !== null) {
                $dateSanitySql .= " AND tenant_id = ?";
                $dateSanityParams[] = $tenantId;
            }

            $dateViolations = $this->db->selectAll($dateSanitySql, $dateSanityParams);
            foreach ($dateViolations as $dv) {
                $inspectedCount++;
                $discrepancies[] = new Discrepancy(
                    tenantId: (int) $dv['tenant_id'],
                    pillar: $this->getPillarKey(),
                    severity: 'critical',
                    entityType: 'financial_transaction',
                    entityId: (string) $dv['id'],
                    description: "Transaksi {$dv['transaction_number']} memiliki tanggal tidak wajar: {$dv['transaction_date']}.",
                    expectedValue: '2000-01-01 s/d 2099-12-31',
                    actualValue: (string) $dv['transaction_date'],
                    autoResolvable: false
                );
            }
        } catch (\Throwable $e) {
            \Core\Log\Logger::getInstance()->warning(static::class . ": " . $e->getMessage());
        }

        return new PillarAuditResult(
            pillar: $this->getPillarKey(),
            name: $this->getPillarName(),
            isBalanced: empty($discrepancies),
            inspectedCount: $inspectedCount,
            discrepancyCount: count($discrepancies),
            discrepancies: $discrepancies
        );
    }

    public function reconcile(?int $tenantId = null, bool $dryRun = false): array
    {
        return [
            'resolved' => 0,
            'messages' => ['Closed-period violations require authorized accounting adjustment entries.'],
        ];
    }
}
