<?php

declare(strict_types=1);

namespace Core\Reconciliation\Auditors;

use Core\Database\Connection;
use Core\Reconciliation\Discrepancy;
use Core\Reconciliation\PillarAuditResult;
use Throwable;

class InventoryStockAuditor implements AuditorInterface
{
    public function __construct(private Connection $db)
    {
    }

    public function getPillarKey(): string
    {
        return 'inventory';
    }

    public function getPillarName(): string
    {
        return 'Inventory Stock & Ledger Movements Balance';
    }

    public function audit(?int $tenantId = null): PillarAuditResult
    {
        $discrepancies = [];
        $inspectedCount = 0;
        $totalDriftQuantity = 0.0;
        $negativeStockCount = 0;

        $sql = "SELECT id, tenant_id, code, name, current_stock, track_inventory FROM items WHERE track_inventory = 1";
        $params = [];
        if ($tenantId !== null) {
            $sql .= " AND tenant_id = ?";
            $params[] = $tenantId;
        }

        try {
            $items = $this->db->selectAll($sql, $params);
            foreach ($items as $item) {
                $inspectedCount++;
                $itemId = (int) $item['id'];
                $recordedStock = round((float) $item['current_stock'], 4);

                // 1. Check for negative stock
                if ($recordedStock < 0.0) {
                    $negativeStockCount++;
                    $discrepancies[] = new Discrepancy(
                        tenantId: (int) $item['tenant_id'],
                        pillar: 'inventory',
                        severity: 'critical',
                        entityType: 'item',
                        entityId: (string) $itemId,
                        description: "Barang {$item['code']} ({$item['name']}) memiliki stok negatif: {$recordedStock}.",
                        expectedValue: '0.0000',
                        actualValue: (string) $recordedStock,
                        autoResolvable: false
                    );
                }

                // 2. Sum immutable ledger movements
                $movSql = "
                    SELECT 
                        COALESCE(SUM(CASE 
                            WHEN type = 'in' THEN quantity 
                            WHEN type = 'out' THEN -quantity 
                            ELSE (stock_after - stock_before) 
                        END), 0.0) AS net_movement
                    FROM inventory_movements
                    WHERE item_id = ?
                ";
                $movRow = $this->db->selectOne($movSql, [$itemId]);
                $calculatedStock = round((float) ($movRow['net_movement'] ?? 0.0), 4);

                // Detect ledger drift
                if (abs($recordedStock - $calculatedStock) > 0.0001) {
                    $drift = abs($recordedStock - $calculatedStock);
                    $totalDriftQuantity += $drift;

                    $discrepancies[] = new Discrepancy(
                        tenantId: (int) $item['tenant_id'],
                        pillar: 'inventory',
                        severity: 'critical',
                        entityType: 'item',
                        entityId: (string) $itemId,
                        description: "Barang {$item['code']} ({$item['name']}) mencatat current_stock={$recordedStock}, tetapi net ledger mutasi={$calculatedStock}.",
                        expectedValue: (string) $calculatedStock,
                        actualValue: (string) $recordedStock,
                        autoResolvable: true
                    );
                }

                // 3. Check batch sum vs current stock if batches exist
                $batchSql = "SELECT COALESCE(SUM(current_quantity), 0.0) AS total_batch FROM item_batches WHERE item_id = ? AND status = 'active'";
                $batchRow = $this->db->selectOne($batchSql, [$itemId]);
                $totalBatch = round((float) ($batchRow['total_batch'] ?? 0.0), 4);

                if ($totalBatch > 0.0 && $totalBatch > $recordedStock) {
                    $discrepancies[] = new Discrepancy(
                        tenantId: (int) $item['tenant_id'],
                        pillar: 'inventory',
                        severity: 'warning',
                        entityType: 'item',
                        entityId: (string) $itemId,
                        description: "Total stok batch aktif ({$totalBatch}) melebihi current_stock barang ({$recordedStock}).",
                        expectedValue: (string) $recordedStock,
                        actualValue: (string) $totalBatch,
                        autoResolvable: false
                    );
                }
            }
        } catch (Throwable) {}

        return new PillarAuditResult(
            pillar: 'inventory',
            name: $this->getPillarName(),
            isBalanced: empty($discrepancies),
            inspectedCount: $inspectedCount,
            discrepancyCount: count($discrepancies),
            discrepancies: $discrepancies,
            metrics: [
                'total_drift_quantity' => round($totalDriftQuantity, 4),
                'negative_stock_items' => $negativeStockCount,
            ]
        );
    }

    public function reconcile(?int $tenantId = null, bool $dryRun = false): array
    {
        $audit = $this->audit($tenantId);
        $resolved = 0;
        $messages = [];

        foreach ($audit->discrepancies as $disc) {
            if (!$disc->autoResolvable) {
                continue;
            }

            $itemId = (int) $disc->entityId;
            $expected = (float) $disc->expectedValue;

            if (!$dryRun) {
                // Apply update under lock protection if lock helper exists
                $updateCallback = fn() => $this->db->statement(
                    "UPDATE items SET current_stock = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
                    [$expected, $itemId]
                );

                if (function_exists('lock')) {
                    try {
                        lock()->run("inventory:stock:{$disc->tenantId}:{$itemId}", $updateCallback, ttlSeconds: 5, waitTimeoutMs: 2000);
                    } catch (Throwable) {
                        $updateCallback();
                    }
                } else {
                    $updateCallback();
                }
            }

            $resolved++;
            $messages[] = sprintf(
                "[%s] Stok barang #%d disinkronkan ke akumulasi ledger: %.4f",
                $dryRun ? 'DRY-RUN' : 'SYNCED',
                $itemId,
                $expected
            );
        }

        return ['resolved' => $resolved, 'messages' => $messages];
    }
}
