<?php

declare(strict_types=1);

namespace Core\Reconciliation\Auditors;

use Core\Database\Connection;
use Core\Reconciliation\Discrepancy;
use Core\Reconciliation\PillarAuditResult;
use Throwable;

class FinanceBillingAuditor implements AuditorInterface
{
    public function __construct(private Connection $db)
    {
    }

    public function getPillarKey(): string
    {
        return 'finance';
    }

    public function getPillarName(): string
    {
        return 'Finance & Billing Mathematical Balance';
    }

    public function audit(?int $tenantId = null): PillarAuditResult
    {
        $discrepancies = [];
        $inspectedCount = 0;
        $totalDriftAmount = 0.0;

        // 1. Audit invoices vs invoice_payments
        $invSql = "
            SELECT i.id, i.tenant_id, i.invoice_number, i.grand_total, i.amount_paid, i.amount_due, i.payment_status,
                   COALESCE(SUM(p.amount), 0.0) AS actual_paid
            FROM invoices i
            LEFT JOIN invoice_payments p ON p.invoice_id = i.id
        ";
        $params = [];
        if ($tenantId !== null) {
            $invSql .= " WHERE i.tenant_id = ?";
            $params[] = $tenantId;
        }
        $invSql .= " GROUP BY i.id";

        try {
            $invoices = $this->db->selectAll($invSql, $params);
            foreach ($invoices as $inv) {
                $inspectedCount++;
                $expectedPaid = round((float) $inv['actual_paid'], 2);
                $recordedPaid = round((float) $inv['amount_paid'], 2);
                $grandTotal = round((float) $inv['grand_total'], 2);
                $amountDue = round((float) $inv['amount_due'], 2);
                $paymentStatus = (string) $inv['payment_status'];

                // Check amount paid drift
                if (abs($expectedPaid - $recordedPaid) > 0.01) {
                    $drift = abs($expectedPaid - $recordedPaid);
                    $totalDriftAmount += $drift;
                    $discrepancies[] = new Discrepancy(
                        tenantId: (int) $inv['tenant_id'],
                        pillar: 'finance',
                        severity: 'critical',
                        entityType: 'invoice',
                        entityId: (string) $inv['id'],
                        description: "Faktur {$inv['invoice_number']} mencatat amount_paid={$recordedPaid}, tetapi total pembayaran riil={$expectedPaid}.",
                        expectedValue: (string) $expectedPaid,
                        actualValue: (string) $recordedPaid,
                        autoResolvable: true
                    );
                }

                // Check payment status consistency
                $expectedStatus = 'unpaid';
                if ($recordedPaid >= $grandTotal && $grandTotal > 0) {
                    $expectedStatus = 'paid';
                } elseif ($recordedPaid > 0) {
                    $expectedStatus = 'partially_paid';
                }

                if ($expectedStatus !== $paymentStatus && $inv['status'] !== 'cancelled') {
                    $discrepancies[] = new Discrepancy(
                        tenantId: (int) $inv['tenant_id'],
                        pillar: 'finance',
                        severity: 'warning',
                        entityType: 'invoice',
                        entityId: (string) $inv['id'],
                        description: "Status pembayaran faktur {$inv['invoice_number']} adalah '{$paymentStatus}', seharusnya '{$expectedStatus}'.",
                        expectedValue: $expectedStatus,
                        actualValue: $paymentStatus,
                        autoResolvable: true
                    );
                }
            }
        } catch (Throwable) {}

        // 2. Audit financial_accounts current_balance vs financial_transactions ledger
        $accSql = "SELECT id, tenant_id, code, name, initial_balance, current_balance FROM financial_accounts";
        $accParams = [];
        if ($tenantId !== null) {
            $accSql .= " WHERE tenant_id = ?";
            $accParams[] = $tenantId;
        }

        try {
            $accounts = $this->db->selectAll($accSql, $accParams);
            foreach ($accounts as $acc) {
                $inspectedCount++;
                $accId = (int) $acc['id'];
                $initBal = (float) $acc['initial_balance'];
                $currBal = round((float) $acc['current_balance'], 2);

                // Calculate ledger transactions
                $txSql = "
                    SELECT 
                        COALESCE(SUM(CASE WHEN source_account_id = ? AND type = 'inflow' THEN amount ELSE 0 END), 0) AS inflows,
                        COALESCE(SUM(CASE WHEN source_account_id = ? AND type IN ('outflow', 'transfer') THEN amount ELSE 0 END), 0) AS outflows,
                        COALESCE(SUM(CASE WHEN target_account_id = ? AND type = 'transfer' THEN amount ELSE 0 END), 0) AS transfer_ins
                    FROM financial_transactions
                    WHERE status = 'completed' AND (source_account_id = ? OR target_account_id = ?)
                ";
                $sums = $this->db->selectOne($txSql, [$accId, $accId, $accId, $accId, $accId]);
                $inflows = (float) ($sums['inflows'] ?? 0);
                $outflows = (float) ($sums['outflows'] ?? 0);
                $transferIns = (float) ($sums['transfer_ins'] ?? 0);

                $calculatedBal = round($initBal + $inflows + $transferIns - $outflows, 2);

                if (abs($currBal - $calculatedBal) > 0.01) {
                    $drift = abs($currBal - $calculatedBal);
                    $totalDriftAmount += $drift;
                    $discrepancies[] = new Discrepancy(
                        tenantId: (int) $acc['tenant_id'],
                        pillar: 'finance',
                        severity: 'critical',
                        entityType: 'financial_account',
                        entityId: (string) $accId,
                        description: "Akun finansial {$acc['code']} ({$acc['name']}) mencatat saldo={$currBal}, tetapi ledger transaksi={$calculatedBal}.",
                        expectedValue: (string) $calculatedBal,
                        actualValue: (string) $currBal,
                        autoResolvable: true
                    );
                }
            }
        } catch (Throwable) {}

        return new PillarAuditResult(
            pillar: 'finance',
            name: $this->getPillarName(),
            isBalanced: empty($discrepancies),
            inspectedCount: $inspectedCount,
            discrepancyCount: count($discrepancies),
            discrepancies: $discrepancies,
            metrics: [
                'total_drift_amount' => round($totalDriftAmount, 2),
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

            if ($disc->entityType === 'financial_account') {
                $accId = (int) $disc->entityId;
                $expected = (float) $disc->expectedValue;

                if (!$dryRun) {
                    $this->db->statement(
                        "UPDATE financial_accounts SET current_balance = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
                        [$expected, $accId]
                    );
                }
                $resolved++;
                $messages[] = sprintf(
                    "[%s] Akun #%d disinkronkan ke saldo ledger: %.2f",
                    $dryRun ? 'DRY-RUN' : 'SYNCED',
                    $accId,
                    $expected
                );
            } elseif ($disc->entityType === 'invoice') {
                $invId = (int) $disc->entityId;

                if (!$dryRun) {
                    // Recalculate and update
                    $row = $this->db->selectOne("
                        SELECT i.grand_total, COALESCE(SUM(p.amount), 0) AS total_paid
                        FROM invoices i
                        LEFT JOIN invoice_payments p ON p.invoice_id = i.id
                        WHERE i.id = ?
                        GROUP BY i.id
                    ", [$invId]);

                    if ($row) {
                        $paid = (float) $row['total_paid'];
                        $grand = (float) $row['grand_total'];
                        $due = max(0.0, $grand - $paid);
                        $status = ($paid >= $grand && $grand > 0) ? 'paid' : ($paid > 0 ? 'partially_paid' : 'unpaid');

                        $this->db->statement("
                            UPDATE invoices 
                            SET amount_paid = ?, amount_due = ?, payment_status = ?, updated_at = CURRENT_TIMESTAMP
                            WHERE id = ?
                        ", [$paid, $due, $status, $invId]);
                    }
                }
                $resolved++;
                $messages[] = sprintf("[%s] Faktur #%d disinkronkan ke pembayaran riil", $dryRun ? 'DRY-RUN' : 'SYNCED', $invId);
            }
        }

        return ['resolved' => $resolved, 'messages' => $messages];
    }
}
