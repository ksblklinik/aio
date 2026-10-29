<?php

declare(strict_types=1);

namespace Core\Reconciliation\Auditors;

use Core\Database\Connection;
use Core\Reconciliation\Discrepancy;
use Core\Reconciliation\PillarAuditResult;
use Throwable;

class DomainBoundaryAuditor implements AuditorInterface
{
    public function __construct(private Connection $db)
    {
    }

    public function getPillarKey(): string
    {
        return 'domain_boundary';
    }

    public function getPillarName(): string
    {
        return 'Domain Values & Sanity Boundary Conformity';
    }

    public function audit(?int $tenantId = null): PillarAuditResult
    {
        $discrepancies = [];
        $inspectedCount = 0;

        // 1. Audit invoices boundaries (grand_total < 0, discount > subtotal, tax < 0)
        try {
            $invSql = "
                SELECT id, tenant_id, invoice_number, subtotal, discount_total, tax_total, grand_total
                FROM invoices
                WHERE (grand_total < 0 
                   OR discount_total < 0 
                   OR (subtotal > 0 AND discount_total > subtotal)
                   OR tax_total < 0)
            ";
            $invParams = [];
            if ($tenantId !== null) {
                $invSql .= " AND tenant_id = ?";
                $invParams[] = $tenantId;
            }

            $invalidInvoices = $this->db->selectAll($invSql, $invParams);
            foreach ($invalidInvoices as $inv) {
                $inspectedCount++;
                $discrepancies[] = new Discrepancy(
                    tenantId: (int) $inv['tenant_id'],
                    pillar: $this->getPillarKey(),
                    severity: 'critical',
                    entityType: 'invoice',
                    entityId: (string) $inv['id'],
                    description: "Faktur {$inv['invoice_number']} memiliki nilai di luar batas domain (subtotal={$inv['subtotal']}, diskon={$inv['discount_total']}, grand_total={$inv['grand_total']}).",
                    expectedValue: 'grand_total >= 0 and discount <= subtotal',
                    actualValue: "grand_total={$inv['grand_total']}, discount={$inv['discount_total']}",
                    autoResolvable: false
                );
            }
        } catch (\Throwable $e) {
            \Core\Log\Logger::getInstance()->warning(static::class . ": " . $e->getMessage());
        }

        // 2. Audit invoice_items (quantity <= 0, unit_price < 0, discount < 0)
        try {
            $itemSql = "
                SELECT id, tenant_id, invoice_id, quantity, unit_price, discount
                FROM invoice_items
                WHERE (quantity <= 0 OR unit_price < 0 OR discount < 0)
            ";
            $itemParams = [];
            if ($tenantId !== null) {
                $itemSql .= " AND tenant_id = ?";
                $itemParams[] = $tenantId;
            }

            $invalidItems = $this->db->selectAll($itemSql, $itemParams);
            foreach ($invalidItems as $ii) {
                $inspectedCount++;
                $discrepancies[] = new Discrepancy(
                    tenantId: (int) $ii['tenant_id'],
                    pillar: $this->getPillarKey(),
                    severity: 'critical',
                    entityType: 'invoice_item',
                    entityId: (string) $ii['id'],
                    description: "Item faktur #{$ii['id']} memiliki kuantitas/harga tidak wajar (qty={$ii['quantity']}, harga={$ii['unit_price']}).",
                    expectedValue: 'qty > 0 and price >= 0',
                    actualValue: "qty={$ii['quantity']}, price={$ii['unit_price']}",
                    autoResolvable: false
                );
            }
        } catch (\Throwable $e) {
            \Core\Log\Logger::getInstance()->warning(static::class . ": " . $e->getMessage());
        }

        // 3. Audit items cost_price & selling_price
        try {
            $prodSql = "SELECT id, tenant_id, code, name, cost_price, selling_price FROM items WHERE (cost_price < 0 OR selling_price < 0)";
            $prodParams = [];
            if ($tenantId !== null) {
                $prodSql .= " AND tenant_id = ?";
                $prodParams[] = $tenantId;
            }

            $invalidProducts = $this->db->selectAll($prodSql, $prodParams);
            foreach ($invalidProducts as $p) {
                $inspectedCount++;
                $discrepancies[] = new Discrepancy(
                    tenantId: (int) $p['tenant_id'],
                    pillar: $this->getPillarKey(),
                    severity: 'warning',
                    entityType: 'item',
                    entityId: (string) $p['id'],
                    description: "Barang {$p['code']} ({$p['name']}) memiliki harga negatif (cost={$p['cost_price']}, selling={$p['selling_price']}).",
                    expectedValue: 'prices >= 0',
                    actualValue: "cost={$p['cost_price']}, selling={$p['selling_price']}",
                    autoResolvable: false
                );
            }
        } catch (\Throwable $e) {
            \Core\Log\Logger::getInstance()->warning(static::class . ": " . $e->getMessage());
        }

        // 4. Audit tariff split rules (split_percentage < 0 or > 100)
        try {
            $splitSql = "SELECT id, tenant_id, name, split_percentage FROM tariff_split_rules WHERE split_percentage < 0 OR split_percentage > 100";
            $splitParams = [];
            if ($tenantId !== null) {
                $splitSql .= " AND tenant_id = ?";
                $splitParams[] = $tenantId;
            }

            $invalidSplits = $this->db->selectAll($splitSql, $splitParams);
            foreach ($invalidSplits as $sr) {
                $inspectedCount++;
                $discrepancies[] = new Discrepancy(
                    tenantId: (int) $sr['tenant_id'],
                    pillar: $this->getPillarKey(),
                    severity: 'critical',
                    entityType: 'tariff_split_rule',
                    entityId: (string) $sr['id'],
                    description: "Aturan split fee {$sr['name']} memiliki persentase di luar rentang 0-100%: {$sr['split_percentage']}%.",
                    expectedValue: '0.00 <= percentage <= 100.00',
                    actualValue: (string) $sr['split_percentage'],
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
            'messages' => ['Domain boundary anomalies require corrective transaction or data sanitization.'],
        ];
    }
}
