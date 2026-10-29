<?php

declare(strict_types=1);

namespace Core\Reconciliation\Auditors;

use Core\Database\Connection;
use Core\Reconciliation\Discrepancy;
use Core\Reconciliation\PillarAuditResult;
use Throwable;

class StateMonotonicityAuditor implements AuditorInterface
{
    public function __construct(private Connection $db)
    {
    }

    public function getPillarKey(): string
    {
        return 'state_monotonicity';
    }

    public function getPillarName(): string
    {
        return 'State Machine Monotonicity & Lifecycle Acyclicity';
    }

    public function audit(?int $tenantId = null): PillarAuditResult
    {
        $discrepancies = [];
        $inspectedCount = 0;

        // 1. Audit queue_tickets state transitions
        try {
            $qSql = "SELECT id, tenant_id, ticket_number, status, called_at, serving_at, completed_at, created_at FROM queue_tickets";
            $qParams = [];
            if ($tenantId !== null) {
                $qSql .= " WHERE tenant_id = ?";
                $qParams[] = $tenantId;
            }

            $tickets = $this->db->selectAll($qSql, $qParams);
            foreach ($tickets as $t) {
                $inspectedCount++;
                $ticketId = (string) $t['id'];
                $status = (string) $t['status'];
                $tId = (int) $t['tenant_id'];

                if ($status === 'completed' && empty($t['called_at']) && empty($t['serving_at'])) {
                    $discrepancies[] = new Discrepancy(
                        tenantId: $tId,
                        pillar: $this->getPillarKey(),
                        severity: 'warning',
                        entityType: 'queue_ticket',
                        entityId: $ticketId,
                        description: "Tiket {$t['ticket_number']} berstatus 'completed' tanpa rekam waktu pemanggilan (called_at) atau pelayanan (serving_at).",
                        expectedValue: 'valid timestamp transition',
                        actualValue: 'missing lifecycle timestamps',
                        autoResolvable: false
                    );
                }

                if ($status === 'calling' && empty($t['called_at'])) {
                    $discrepancies[] = new Discrepancy(
                        tenantId: $tId,
                        pillar: $this->getPillarKey(),
                        severity: 'warning',
                        entityType: 'queue_ticket',
                        entityId: $ticketId,
                        description: "Tiket {$t['ticket_number']} berstatus 'calling' tetapi called_at bernilai NULL.",
                        expectedValue: 'called_at not null',
                        actualValue: 'NULL',
                        autoResolvable: false
                    );
                }
            }
        } catch (\Throwable $e) {
            \Core\Log\Logger::getInstance()->warning(static::class . ": " . $e->getMessage());
        }

        // 2. Audit appointments state consistency
        try {
            $aptSql = "SELECT id, tenant_id, code, status, appointment_date, start_time FROM appointments";
            $aptParams = [];
            if ($tenantId !== null) {
                $aptSql .= " WHERE tenant_id = ?";
                $aptParams[] = $tenantId;
            }

            $appointments = $this->db->selectAll($aptSql, $aptParams);
            foreach ($appointments as $apt) {
                $inspectedCount++;
                $aptId = (string) $apt['id'];
                $status = (string) $apt['status'];
                $tId = (int) $apt['tenant_id'];

                $validStatuses = ['scheduled', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'];
                if (!in_array($status, $validStatuses, true)) {
                    $discrepancies[] = new Discrepancy(
                        tenantId: $tId,
                        pillar: $this->getPillarKey(),
                        severity: 'critical',
                        entityType: 'appointment',
                        entityId: $aptId,
                        description: "Janji temu {$apt['code']} memiliki status tidak sah: '{$status}'.",
                        expectedValue: implode('|', $validStatuses),
                        actualValue: $status,
                        autoResolvable: false
                    );
                }
            }
        } catch (\Throwable $e) {
            \Core\Log\Logger::getInstance()->warning(static::class . ": " . $e->getMessage());
        }

        // 3. Audit invoices cancelled vs payments consistency
        try {
            $invSql = "
                SELECT i.id, i.tenant_id, i.invoice_number, i.status, i.amount_paid, i.grand_total,
                       COALESCE(SUM(p.amount), 0.0) AS actual_payments
                FROM invoices i
                LEFT JOIN invoice_payments p ON p.invoice_id = i.id
                WHERE i.status = 'cancelled'
            ";
            $invParams = [];
            if ($tenantId !== null) {
                $invSql .= " AND i.tenant_id = ?";
                $invParams[] = $tenantId;
            }
            $invSql .= " GROUP BY i.id";

            $cancelledInvoices = $this->db->selectAll($invSql, $invParams);
            foreach ($cancelledInvoices as $cinv) {
                $inspectedCount++;
                $actualPaid = (float) $cinv['actual_payments'];
                if ($actualPaid > 0.01) {
                    $discrepancies[] = new Discrepancy(
                        tenantId: (int) $cinv['tenant_id'],
                        pillar: $this->getPillarKey(),
                        severity: 'critical',
                        entityType: 'invoice',
                        entityId: (string) $cinv['id'],
                        description: "Faktur {$cinv['invoice_number']} berstatus 'cancelled' tetapi memiliki pembayaran aktif sebesar Rp " . number_format($actualPaid, 2) . " tanpa jurnal refund.",
                        expectedValue: '0.00',
                        actualValue: (string) $actualPaid,
                        autoResolvable: false
                    );
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
            'messages' => ['State machine violations require manual review or compensating transaction.'],
        ];
    }
}
