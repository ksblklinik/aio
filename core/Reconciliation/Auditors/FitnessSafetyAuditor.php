<?php

declare(strict_types=1);

namespace Core\Reconciliation\Auditors;

use Core\Database\Connection;
use Core\Reconciliation\Discrepancy;
use Core\Reconciliation\PillarAuditResult;
use Throwable;

class FitnessSafetyAuditor implements AuditorInterface
{
    public function __construct(private Connection $db)
    {
    }

    public function getPillarKey(): string
    {
        return 'fitness_safety';
    }

    public function getPillarName(): string
    {
        return 'Quarantine, Recall & Safety Fitness Invariance (Inv 28)';
    }

    public function audit(?int $tenantId = null): PillarAuditResult
    {
        $discrepancies = [];
        $inspectedCount = 0;
        $nowDate = date('Y-m-d');

        // 1. Audit active batches past their expiry date
        try {
            $batchSql = "SELECT b.id, b.tenant_id, b.item_id, b.batch_number, b.expiry_date, b.current_quantity, b.status 
                         FROM item_batches b 
                         WHERE b.expiry_date < ? AND b.status = 'active'";
            $batchParams = [$nowDate];
            if ($tenantId !== null) {
                $batchSql .= " AND b.tenant_id = ?";
                $batchParams[] = $tenantId;
            }

            $expiredActiveBatches = $this->db->selectAll($batchSql, $batchParams);
            foreach ($expiredActiveBatches as $b) {
                $inspectedCount++;
                $discrepancies[] = new Discrepancy(
                    tenantId: (int) $b['tenant_id'],
                    pillar: $this->getPillarKey(),
                    severity: 'warning',
                    entityType: 'item_batch',
                    entityId: (string) $b['id'],
                    description: "Batch obat/barang [{$b['batch_number']}] telah kedaluwarsa pada {$b['expiry_date']} namun masih berstatus 'active' (stok: {$b['current_quantity']}).",
                    expectedValue: 'status = expired',
                    actualValue: 'status = active',
                    autoResolvable: true
                );
            }
        } catch (Throwable) {
        }

        // 2. Audit stock movements out for expired or quarantined batches
        try {
            $movSql = "SELECT m.id, m.tenant_id, m.item_id, m.batch_id, m.quantity, m.type, m.created_at,
                              b.batch_number, b.expiry_date, b.status as batch_status 
                       FROM inventory_movements m 
                       JOIN item_batches b ON m.batch_id = b.id 
                       WHERE m.type = 'out'";
            $movParams = [];
            if ($tenantId !== null) {
                $movSql .= " AND m.tenant_id = ?";
                $movParams[] = $tenantId;
            }

            $movements = $this->db->selectAll($movSql, $movParams);
            foreach ($movements as $m) {
                $inspectedCount++;
                $movDate = substr((string) $m['created_at'], 0, 10);
                $expDate = (string) $m['expiry_date'];
                $batchStatus = (string) $m['batch_status'];

                // Dispensed after expiry date
                if ($expDate !== '' && $movDate > $expDate) {
                    $discrepancies[] = new Discrepancy(
                        tenantId: (int) $m['tenant_id'],
                        pillar: $this->getPillarKey(),
                        severity: 'critical',
                        entityType: 'inventory_movement',
                        entityId: (string) $m['id'],
                        description: "Pelanggaran Keamanan Fisik: Batch [{$m['batch_number']}] dikeluarkan pada {$movDate}, melampaui tanggal kedaluwarsa {$expDate}.",
                        expectedValue: "dispensed_at <= {$expDate}",
                        actualValue: "dispensed_at = {$movDate}",
                        autoResolvable: false
                    );
                }

                // Dispensed from recalled or quarantined batch
                if (in_array($batchStatus, ['recalled', 'quarantined'], true)) {
                    $discrepancies[] = new Discrepancy(
                        tenantId: (int) $m['tenant_id'],
                        pillar: $this->getPillarKey(),
                        severity: 'critical',
                        entityType: 'inventory_movement',
                        entityId: (string) $m['id'],
                        description: "Pelanggaran Karantina: Mutasi keluar dilakukan terhadap batch [{$m['batch_number']}] yang berstatus '{$batchStatus}'.",
                        expectedValue: 'no movement from recalled/quarantined batch',
                        actualValue: "movement qty {$m['quantity']}",
                        autoResolvable: false
                    );
                }
            }
        } catch (Throwable) {
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
        $messages = [];
        $resolved = 0;
        $nowDate = date('Y-m-d');

        try {
            $sql = "SELECT id, batch_number, expiry_date FROM item_batches WHERE expiry_date < ? AND status = 'active'";
            $params = [$nowDate];
            if ($tenantId !== null) {
                $sql .= " AND tenant_id = ?";
                $params[] = $tenantId;
            }

            $batches = $this->db->selectAll($sql, $params);
            foreach ($batches as $b) {
                if (!$dryRun) {
                    $this->db->statement("UPDATE item_batches SET status = 'expired' WHERE id = ?", [$b['id']]);
                }
                $resolved++;
                $messages[] = "Batch [{$b['batch_number']}] telah ditransisikan otomatis menjadi 'expired' (kedaluwarsa: {$b['expiry_date']}).";
            }
        } catch (Throwable $e) {
            $messages[] = "Reconcile failed: " . $e->getMessage();
        }

        return [
            'resolved' => $resolved,
            'messages' => $messages,
        ];
    }
}
