<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Reconciliation\ReconciliationManagerInterface;
use Throwable;

class ReconcileAuditCommand extends Command
{
    protected string $signature = 'reconcile:audit';
    protected string $description = 'Audit integritas data dan keseimbangan matematis 5 pilar universal lintas modul';

    public function handle(array $arguments = [], array $options = []): int
    {
        /** @var ReconciliationManagerInterface $reconciler */
        $reconciler = $this->container->get(ReconciliationManagerInterface::class);

        $pillar = isset($options['pillar']) ? (string) $options['pillar'] : null;
        $tenantId = isset($options['tenant']) ? (int) $options['tenant'] : null;

        $this->info("Menjalankan audit integritas matematis lintas modul...");
        if ($tenantId !== null) {
            $this->line("Filter Tenant ID: {$tenantId}");
        }
        if ($pillar !== null) {
            $this->line("Filter Pilar: {$pillar}");
        }

        try {
            $report = $reconciler->audit($tenantId, $pillar);

            $this->line(str_repeat('-', 80));
            $this->line(sprintf(
                "%-28s | %-12s | %-12s | %s",
                "Pilar Universal",
                "Inspeksi",
                "Diskrepansi",
                "Status"
            ));
            $this->line(str_repeat('-', 80));

            foreach ($report->pillars as $key => $pResult) {
                $statusStr = $pResult->isBalanced
                    ? "\033[32m✔ SEIMBANG\033[0m"
                    : "\033[31m✖ ANOMALI (" . $pResult->discrepancyCount . ")\033[0m";

                $this->line(sprintf(
                    "%-28s | %-12d | %-12d | %s",
                    $pResult->name,
                    $pResult->inspectedCount,
                    $pResult->discrepancyCount,
                    $statusStr
                ));
            }

            $this->line(str_repeat('-', 80));
            $this->line(sprintf("Total Entitas Diperiksa : %d", $report->totalInspections));
            $this->line(sprintf("Total Diskrepansi      : %d", $report->discrepancyCount));
            $this->line(sprintf("Diskrepansi Kritis     : %d", $report->criticalCount));
            $this->line(str_repeat('-', 80));

            if (!$report->isHealthy) {
                $this->warn("Daftar Temuan Diskrepansi (Maksimal 15 teratas):");
                $shown = 0;
                foreach ($report->allDiscrepancies as $disc) {
                    $shown++;
                    if ($shown > 15) {
                        $this->line(sprintf("... dan %d diskrepansi lainnya.", count($report->allDiscrepancies) - 15));
                        break;
                    }

                    $sevTag = match ($disc->severity) {
                        'critical' => "\033[31m[CRITICAL]\033[0m",
                        'warning' => "\033[33m[WARNING]\033[0m",
                        default => "\033[36m[INFO]\033[0m",
                    };

                    $this->line(sprintf(
                        " %s [%s] %s #%s (Tenant #%d): %s",
                        $sevTag,
                        strtoupper($disc->pillar),
                        $disc->entityType,
                        $disc->entityId,
                        $disc->tenantId,
                        $disc->description
                    ));
                    if ($disc->expectedValue !== null || $disc->actualValue !== null) {
                        $this->line(sprintf(
                            "     -> Ekspektasi: %s | Aktual di DB: %s",
                            $disc->expectedValue ?? 'N/A',
                            $disc->actualValue ?? 'N/A'
                        ));
                    }
                }

                $this->line("");
                $this->info("Jalankan 'php aio reconcile:sync' untuk rekonsiliasi data yang dapat diperbaiki otomatis.");
                return 1;
            }

            $this->success($report->getSummary());
            return 0;
        } catch (Throwable $e) {
            $this->error("Gagal menjalankan audit integritas: " . $e->getMessage());
            return 1;
        }
    }
}
