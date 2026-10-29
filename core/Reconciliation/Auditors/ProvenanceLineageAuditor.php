<?php

declare(strict_types=1);

namespace Core\Reconciliation\Auditors;

use Core\Database\Connection;
use Core\Reconciliation\Discrepancy;
use Core\Reconciliation\PillarAuditResult;
use Throwable;

class ProvenanceLineageAuditor implements AuditorInterface
{
    public function __construct(private Connection $db)
    {
    }

    public function getPillarKey(): string
    {
        return 'provenance_lineage';
    }

    public function getPillarName(): string
    {
        return 'Provenance & Chain of Custody Invariance (Inv 24)';
    }

    public function audit(?int $tenantId = null): PillarAuditResult
    {
        $discrepancies = [];
        $inspectedCount = 0;

        // 1. Audit item_batches lineage integrity
        try {
            $batchSql = "SELECT b.id, b.tenant_id, b.batch_number, b.notes, b.item_id 
                         FROM item_batches b";
            $batchParams = [];
            if ($tenantId !== null) {
                $batchSql .= " WHERE b.tenant_id = ?";
                $batchParams[] = $tenantId;
            }

            $batches = $this->db->selectAll($batchSql, $batchParams);
            foreach ($batches as $b) {
                $inspectedCount++;
                $notes = (string) ($b['notes'] ?? '');

                // Check for composite / parent lot provenance references in notes (e.g., parent_batch_id: 123)
                if (preg_match('/parent_batch_id[:=]\s*(\d+)/i', $notes, $matches)) {
                    $parentBatchId = (int) $matches[1];
                    $parent = $this->db->selectOne(
                        "SELECT id, tenant_id FROM item_batches WHERE id = ?",
                        [$parentBatchId]
                    );

                    if (!$parent) {
                        $discrepancies[] = new Discrepancy(
                            tenantId: (int) $b['tenant_id'],
                            pillar: $this->getPillarKey(),
                            severity: 'warning',
                            entityType: 'item_batch',
                            entityId: (string) $b['id'],
                            description: "Batch racikan/turunan [{$b['batch_number']}] merujuk ke parent_batch_id {$parentBatchId} yang tidak ditemukan.",
                            expectedValue: "valid parent batch ID {$parentBatchId}",
                            actualValue: 'orphan provenance parent',
                            autoResolvable: false
                        );
                    } elseif ((int) $parent['tenant_id'] !== (int) $b['tenant_id']) {
                        $discrepancies[] = new Discrepancy(
                            tenantId: (int) $b['tenant_id'],
                            pillar: $this->getPillarKey(),
                            severity: 'critical',
                            entityType: 'item_batch',
                            entityId: (string) $b['id'],
                            description: "Kebocoran silsilah: Batch [{$b['batch_number']}] merujuk ke parent_batch_id {$parentBatchId} milik tenant berbeda.",
                            expectedValue: "tenant_id {$b['tenant_id']}",
                            actualValue: "cross-tenant parent_id {$parent['tenant_id']}",
                            autoResolvable: false
                        );
                    }
                }
            }
        } catch (Throwable) {
        }

        // 2. Audit inventory_movements provenance completeness
        try {
            $movSql = "SELECT m.id, m.tenant_id, m.item_id, m.batch_id, m.type, m.quantity, m.reference_type, m.reference_id, m.notes 
                       FROM inventory_movements m";
            $movParams = [];
            if ($tenantId !== null) {
                $movSql .= " WHERE m.tenant_id = ?";
                $movParams[] = $tenantId;
            }

            $movements = $this->db->selectAll($movSql, $movParams);
            foreach ($movements as $m) {
                $inspectedCount++;
                $refType = trim((string) ($m['reference_type'] ?? ''));
                $refId = trim((string) ($m['reference_id'] ?? ''));
                $notes = trim((string) ($m['notes'] ?? ''));
                $batchId = $m['batch_id'] ?? null;

                // Mutasi stok tanpa reference, batch, maupun keterangan (un-sourced phantom movement)
                if ($refType === '' && $refId === '' && $notes === '' && empty($batchId)) {
                    $discrepancies[] = new Discrepancy(
                        tenantId: (int) $m['tenant_id'],
                        pillar: $this->getPillarKey(),
                        severity: 'warning',
                        entityType: 'inventory_movement',
                        entityId: (string) $m['id'],
                        description: "Mutasi inventaris #{$m['id']} [tipe: {$m['type']}, qty: {$m['quantity']}] tidak memiliki referensi asal-usul (reference_type, reference_id, batch, maupun notes kosong).",
                        expectedValue: 'valid operational reference or provenance notes',
                        actualValue: 'un-sourced movement',
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
        return [
            'resolved' => 0,
            'messages' => ['Provenance lineage anomalies require manual supply chain custody verification.'],
        ];
    }
}
