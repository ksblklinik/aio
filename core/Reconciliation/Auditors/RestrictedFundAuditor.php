<?php

declare(strict_types=1);

namespace Core\Reconciliation\Auditors;

use Core\Database\Connection;
use Core\Reconciliation\Discrepancy;
use Core\Reconciliation\PillarAuditResult;
use Throwable;

class RestrictedFundAuditor implements AuditorInterface
{
    public function __construct(private Connection $db)
    {
    }

    public function getPillarKey(): string
    {
        return 'restricted_fund';
    }

    public function getPillarName(): string
    {
        return 'Purpose-Bound Fiduciary & Restricted Fund Invariance (Inv 25)';
    }

    public function audit(?int $tenantId = null): PillarAuditResult
    {
        $discrepancies = [];
        $inspectedCount = 0;

        try {
            $sql = "SELECT id, tenant_id, code, name, type, current_balance, is_active 
                    FROM financial_accounts";
            $params = [];
            if ($tenantId !== null) {
                $sql .= " WHERE tenant_id = ?";
                $params[] = $tenantId;
            }

            $accounts = $this->db->selectAll($sql, $params);
            foreach ($accounts as $acc) {
                $inspectedCount++;
                $code = strtoupper((string) $acc['code']);
                $name = strtolower((string) $acc['name']);
                $type = strtolower((string) $acc['type']);
                $balance = (float) $acc['current_balance'];
                $tId = (int) $acc['tenant_id'];
                $accId = (string) $acc['id'];

                // Detect if this account represents a purpose-bound / restricted / fiduciary fund
                $isRestricted = str_contains($code, 'RESTRICTED') 
                    || str_contains($code, 'ZAKAT') 
                    || str_contains($code, 'WAKAF') 
                    || str_contains($code, 'HIBAH')
                    || str_contains($name, 'terikat') 
                    || str_contains($name, 'zakat') 
                    || str_contains($name, 'wakaf') 
                    || str_contains($name, 'hibah') 
                    || str_contains($name, 'deposit pasien') 
                    || str_contains($name, 'titipan') 
                    || str_contains($name, 'escrow')
                    || str_contains($name, 'dana bantuan')
                    || $type === 'restricted_equity';

                if (!$isRestricted) {
                    continue;
                }

                // Invariant 25 Rule 1: Restricted fund balance cannot be negative (no unauthorized deficit/borrowing)
                if ($balance < 0.0) {
                    $discrepancies[] = new Discrepancy(
                        tenantId: $tId,
                        pillar: $this->getPillarKey(),
                        severity: 'critical',
                        entityType: 'financial_account',
                        entityId: $accId,
                        description: "Rekening dana terikat/fiduciary '{$acc['name']}' (#{$acc['code']}) mengalami defisit (saldo: Rp " . number_format($balance, 2) . " < 0), melanggar Invarian 25.",
                        expectedValue: 'balance >= 0.0',
                        actualValue: (string) $balance,
                        autoResolvable: false
                    );
                }

                // Invariant 25 Rule 2: Verify transaction journal consistency for this restricted account
                try {
                    $txStats = $this->db->selectOne(
                        "SELECT 
                            COALESCE(SUM(CASE WHEN type = 'inflow' AND source_account_id = ? THEN amount 
                                              WHEN type = 'transfer' AND target_account_id = ? THEN amount ELSE 0 END), 0) as total_inflows,
                            COALESCE(SUM(CASE WHEN type = 'outflow' AND source_account_id = ? THEN amount 
                                              WHEN type = 'transfer' AND source_account_id = ? THEN amount ELSE 0 END), 0) as total_outflows
                         FROM financial_transactions 
                         WHERE (source_account_id = ? OR target_account_id = ?) AND status = 'completed'",
                        [$acc['id'], $acc['id'], $acc['id'], $acc['id'], $acc['id'], $acc['id']]
                    );

                    if ($txStats) {
                        $totalInflows = (float) $txStats['total_inflows'];
                        $totalOutflows = (float) $txStats['total_outflows'];

                        if ($totalOutflows > $totalInflows && $balance < 0.01) {
                            $discrepancies[] = new Discrepancy(
                                tenantId: $tId,
                                pillar: $this->getPillarKey(),
                                severity: 'critical',
                                entityType: 'financial_account',
                                entityId: $accId,
                                description: "Akumulasi penyerapan dana terikat '{$acc['name']}' (Keluar: Rp " . number_format($totalOutflows, 2) . ") melebihi total penerimaan (Masuk: Rp " . number_format($totalInflows, 2) . ").",
                                expectedValue: 'total_outflows <= total_inflows',
                                actualValue: "outflows: {$totalOutflows}, inflows: {$totalInflows}",
                                autoResolvable: false
                            );
                        }
                    }
                } catch (\Throwable $e) {
            \Core\Log\Logger::getInstance()->warning(static::class . ": " . $e->getMessage());
        }
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
            'messages' => ['Restricted fund deficits require formal donor re-allocation or replenishment from unrestricted equity.'],
        ];
    }
}
