<?php

declare(strict_types=1);

namespace Core\Reconciliation\Auditors;

use Core\Database\Connection;
use Core\Reconciliation\Discrepancy;
use Core\Reconciliation\PillarAuditResult;
use Throwable;

class TenantReferentialAuditor implements AuditorInterface
{
    public function __construct(private Connection $db)
    {
    }

    public function getPillarKey(): string
    {
        return 'tenant';
    }

    public function getPillarName(): string
    {
        return 'Multi-Tenant & Relational Integrity';
    }

    public function audit(?int $tenantId = null): PillarAuditResult
    {
        $discrepancies = [];
        $inspectedCount = 0;

        // 1. Cross-Tenant Leakage Checks
        $leakageChecks = [
            [
                'child_table' => 'invoice_items',
                'parent_table' => 'invoices',
                'foreign_key' => 'invoice_id',
                'entity_type' => 'invoice_item',
            ],
            [
                'child_table' => 'invoice_payments',
                'parent_table' => 'invoices',
                'foreign_key' => 'invoice_id',
                'entity_type' => 'invoice_payment',
            ],
            [
                'child_table' => 'tariff_fee_allocations',
                'parent_table' => 'invoices',
                'foreign_key' => 'invoice_id',
                'entity_type' => 'tariff_fee_allocation',
            ],
            [
                'child_table' => 'item_batches',
                'parent_table' => 'items',
                'foreign_key' => 'item_id',
                'entity_type' => 'item_batch',
            ],
            [
                'child_table' => 'inventory_stock_locations',
                'parent_table' => 'items',
                'foreign_key' => 'item_id',
                'entity_type' => 'stock_location',
            ],
            [
                'child_table' => 'financial_transactions',
                'parent_table' => 'financial_accounts',
                'foreign_key' => 'source_account_id',
                'entity_type' => 'financial_transaction',
            ],
        ];

        foreach ($leakageChecks as $check) {
            $cTable = $check['child_table'];
            $pTable = $check['parent_table'];
            $fk = $check['foreign_key'];
            $entityType = $check['entity_type'];

            $sql = "
                SELECT c.id AS child_id, c.tenant_id AS child_tenant_id,
                       p.id AS parent_id, p.tenant_id AS parent_tenant_id
                FROM {$cTable} c
                INNER JOIN {$pTable} p ON p.id = c.{$fk}
                WHERE c.tenant_id != p.tenant_id
            ";
            $params = [];
            if ($tenantId !== null) {
                $sql .= " AND (c.tenant_id = ? OR p.tenant_id = ?)";
                $params[] = $tenantId;
                $params[] = $tenantId;
            }

            try {
                $leaks = $this->db->selectAll($sql, $params);
                foreach ($leaks as $leak) {
                    $inspectedCount++;
                    $discrepancies[] = new Discrepancy(
                        pillar: 'tenant',
                        severity: 'critical',
                        entityType: $entityType,
                        entityId: (string) $leak['child_id'],
                        tenantId: (int) $leak['child_tenant_id'],
                        description: sprintf(
                            "Kebocoran multi-tenant pada %s #%d: tenant_id rekaman anak (%d) berbeda dengan tenant_id induk %s #%d (%d)",
                            $cTable,
                            $leak['child_id'],
                            $leak['child_tenant_id'],
                            $pTable,
                            $leak['parent_id'],
                            $leak['parent_tenant_id']
                        ),
                        expectedValue: (string) $leak['parent_tenant_id'],
                        actualValue: (string) $leak['child_tenant_id'],
                        autoResolvable: true
                    );
                }
            } catch (Throwable) {
                // Table might not exist or be empty in some test environments
            }
        }

        // 2. Orphan Records Checks (Child references non-existent parent)
        $orphanChecks = [
            [
                'child_table' => 'invoice_items',
                'parent_table' => 'invoices',
                'foreign_key' => 'invoice_id',
                'entity_type' => 'invoice_item',
            ],
            [
                'child_table' => 'invoice_payments',
                'parent_table' => 'invoices',
                'foreign_key' => 'invoice_id',
                'entity_type' => 'invoice_payment',
            ],
            [
                'child_table' => 'tariff_fee_allocations',
                'parent_table' => 'invoices',
                'foreign_key' => 'invoice_id',
                'entity_type' => 'tariff_fee_allocation',
            ],
            [
                'child_table' => 'item_batches',
                'parent_table' => 'items',
                'foreign_key' => 'item_id',
                'entity_type' => 'item_batch',
            ],
            [
                'child_table' => 'inventory_stock_locations',
                'parent_table' => 'items',
                'foreign_key' => 'item_id',
                'entity_type' => 'stock_location',
            ],
        ];

        foreach ($orphanChecks as $check) {
            $cTable = $check['child_table'];
            $pTable = $check['parent_table'];
            $fk = $check['foreign_key'];
            $entityType = $check['entity_type'];

            $sql = "
                SELECT c.id AS child_id, c.tenant_id, c.{$fk} AS parent_fk
                FROM {$cTable} c
                LEFT JOIN {$pTable} p ON p.id = c.{$fk}
                WHERE c.{$fk} IS NOT NULL AND p.id IS NULL
            ";
            $params = [];
            if ($tenantId !== null) {
                $sql .= " AND c.tenant_id = ?";
                $params[] = $tenantId;
            }

            try {
                $orphans = $this->db->selectAll($sql, $params);
                foreach ($orphans as $orphan) {
                    $inspectedCount++;
                    $discrepancies[] = new Discrepancy(
                        pillar: 'tenant',
                        severity: 'warning',
                        entityType: $entityType,
                        entityId: (string) $orphan['child_id'],
                        tenantId: (int) $orphan['tenant_id'],
                        description: sprintf(
                            "Record yatim (orphan) pada %s #%d: merujuk ke induk %s #%d yang tidak ada",
                            $cTable,
                            $orphan['child_id'],
                            $pTable,
                            $orphan['parent_fk']
                        ),
                        expectedValue: "Existing {$pTable}.id",
                        actualValue: "Missing parent #{$orphan['parent_fk']}",
                        autoResolvable: false
                    );
                }
            } catch (Throwable) {
                // Table might not exist or be empty
            }
        }

        // 3. Ghost Tenant Check (Records pointing to non-existent tenant in tenants table)
        $tenantTables = ['invoices', 'items', 'financial_accounts', 'contacts', 'document_seals'];
        foreach ($tenantTables as $table) {
            $sql = "
                SELECT t_sub.id, t_sub.tenant_id
                FROM {$table} t_sub
                LEFT JOIN tenants t ON t.id = t_sub.tenant_id
                WHERE t.id IS NULL
            ";
            $params = [];
            if ($tenantId !== null) {
                $sql .= " AND t_sub.tenant_id = ?";
                $params[] = $tenantId;
            }

            try {
                $ghosts = $this->db->selectAll($sql, $params);
                foreach ($ghosts as $ghost) {
                    $inspectedCount++;
                    $discrepancies[] = new Discrepancy(
                        pillar: 'tenant',
                        severity: 'critical',
                        entityType: $table,
                        entityId: (string) $ghost['id'],
                        tenantId: (int) $ghost['tenant_id'],
                        description: sprintf(
                            "Rekaman %s #%d merujuk ke tenant_id #%d yang tidak terdaftar di tabel tenants",
                            $table,
                            $ghost['id'],
                            $ghost['tenant_id']
                        ),
                        expectedValue: "Valid tenant ID",
                        actualValue: "Non-existent tenant #{$ghost['tenant_id']}",
                        autoResolvable: false
                    );
                }
            } catch (Throwable) {
                // Ignore if table missing
            }
        }

        return new PillarAuditResult(
            pillar: 'tenant',
            name: $this->getPillarName(),
            isBalanced: empty($discrepancies),
            inspectedCount: $inspectedCount,
            discrepancyCount: count($discrepancies),
            discrepancies: $discrepancies,
            metrics: [
                'cross_tenant_leaks' => count(array_filter($discrepancies, fn($d) => $d->autoResolvable)),
                'orphan_records' => count(array_filter($discrepancies, fn($d) => !$d->autoResolvable)),
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

            $correctTenantId = (int) $disc->expectedValue;
            $recordId = (int) $disc->entityId;

            $tableMap = [
                'invoice_item' => 'invoice_items',
                'invoice_payment' => 'invoice_payments',
                'tariff_fee_allocation' => 'tariff_fee_allocations',
                'item_batch' => 'item_batches',
                'stock_location' => 'inventory_stock_locations',
                'financial_transaction' => 'financial_transactions',
            ];

            if (isset($tableMap[$disc->entityType])) {
                $table = $tableMap[$disc->entityType];
                if (!$dryRun) {
                    $this->db->statement(
                        "UPDATE {$table} SET tenant_id = ? WHERE id = ?",
                        [$correctTenantId, $recordId]
                    );
                }
                $resolved++;
                $messages[] = sprintf(
                    "[%s] %s #%d tenant_id disinkronkan ke tenant induk: %d",
                    $dryRun ? 'DRY-RUN' : 'SYNCED',
                    $table,
                    $recordId,
                    $correctTenantId
                );
            }
        }

        return ['resolved' => $resolved, 'messages' => $messages];
    }
}
