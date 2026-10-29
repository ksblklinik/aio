<?php

declare(strict_types=1);

namespace Core\Reconciliation\Auditors;

use Core\Database\Connection;
use Core\Reconciliation\Discrepancy;
use Core\Reconciliation\PillarAuditResult;
use Throwable;

class TariffSplitAuditor implements AuditorInterface
{
    public function __construct(private Connection $db)
    {
    }

    public function getPillarKey(): string
    {
        return 'tariff';
    }

    public function getPillarName(): string
    {
        return 'Tariff Split-Fees & Invoice Revenue Balance';
    }

    public function audit(?int $tenantId = null): PillarAuditResult
    {
        $discrepancies = [];
        $inspectedCount = 0;
        $overAllocatedInvoices = 0;

        // 1. Audit invoice total vs sum of fee allocations
        $allocSql = "
            SELECT 
                a.invoice_id, 
                a.tenant_id, 
                i.invoice_number, 
                i.grand_total,
                SUM(a.amount) AS total_splits
            FROM tariff_fee_allocations a
            JOIN invoices i ON i.id = a.invoice_id
            WHERE a.status != 'cancelled'
        ";
        $params = [];
        if ($tenantId !== null) {
            $allocSql .= " AND a.tenant_id = ?";
            $params[] = $tenantId;
        }
        $allocSql .= " GROUP BY a.invoice_id";

        try {
            $rows = $this->db->selectAll($allocSql, $params);
            foreach ($rows as $r) {
                $inspectedCount++;
                $grandTotal = round((float) $r['grand_total'], 2);
                $totalSplits = round((float) $r['total_splits'], 2);

                if ($totalSplits > $grandTotal && $grandTotal > 0.0) {
                    $overAllocatedInvoices++;
                    $diff = round($totalSplits - $grandTotal, 2);

                    $discrepancies[] = new Discrepancy(
                        tenantId: (int) $r['tenant_id'],
                        pillar: 'tariff',
                        severity: 'critical',
                        entityType: 'invoice_split_allocation',
                        entityId: (string) $r['invoice_id'],
                        description: "Total alokasi split fee ({$totalSplits}) pada Faktur {$r['invoice_number']} melebihi grand total faktur ({$grandTotal}) sebesar {$diff}.",
                        expectedValue: (string) $grandTotal,
                        actualValue: (string) $totalSplits,
                        autoResolvable: false
                    );
                }
            }
        } catch (\Throwable $e) {
            \Core\Log\Logger::getInstance()->warning(static::class . ": " . $e->getMessage());
        }

        // 2. Audit split rules percentages exceeding 100%
        $ruleSql = "
            SELECT tenant_id, item_id, price_book_id, SUM(split_value) AS total_percentage
            FROM tariff_split_rules
            WHERE split_type = 'percentage'
        ";
        $ruleParams = [];
        if ($tenantId !== null) {
            $ruleSql .= " AND tenant_id = ?";
            $ruleParams[] = $tenantId;
        }
        $ruleSql .= " GROUP BY tenant_id, item_id, price_book_id";

        try {
            $ruleRows = $this->db->selectAll($ruleSql, $ruleParams);
            foreach ($ruleRows as $rr) {
                $inspectedCount++;
                $totPct = round((float) $rr['total_percentage'], 2);
                if ($totPct > 100.0) {
                    $itemLabel = $rr['item_id'] ? "Item #{$rr['item_id']}" : "Seluruh Item";
                    $discrepancies[] = new Discrepancy(
                        tenantId: (int) $rr['tenant_id'],
                        pillar: 'tariff',
                        severity: 'critical',
                        entityType: 'tariff_split_rule',
                        entityId: (string) ($rr['item_id'] ?? 'global'),
                        description: "Total persentase aturan split fee untuk {$itemLabel} mencapai {$totPct}% (> 100%).",
                        expectedValue: '100.00',
                        actualValue: (string) $totPct,
                        autoResolvable: false
                    );
                }
            }
        } catch (\Throwable $e) {
            \Core\Log\Logger::getInstance()->warning(static::class . ": " . $e->getMessage());
        }

        return new PillarAuditResult(
            pillar: 'tariff',
            name: $this->getPillarName(),
            isBalanced: empty($discrepancies),
            inspectedCount: $inspectedCount,
            discrepancyCount: count($discrepancies),
            discrepancies: $discrepancies,
            metrics: [
                'over_allocated_invoices' => $overAllocatedInvoices,
            ]
        );
    }

    public function reconcile(?int $tenantId = null, bool $dryRun = false): array
    {
        // Split fee over-allocation requires business human judgment, so we flag it and log it for operator resolution.
        $audit = $this->audit($tenantId);
        return [
            'resolved' => 0,
            'messages' => [
                sprintf("Tariff split fees inspected: %d issues identified requiring manual adjustment.", $audit->discrepancyCount)
            ]
        ];
    }
}
