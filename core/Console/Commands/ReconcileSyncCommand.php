<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Reconciliation\ReconciliationManagerInterface;
use Throwable;

class ReconcileSyncCommand extends Command
{
    protected string $signature = 'reconcile:sync';
    protected string $description = 'Eksekusi perbaikan dan rekonsiliasi data otomatis lintas modul (opsi --dry-run untuk simulasi)';

    public function handle(array $arguments = [], array $options = []): int
    {
        /** @var ReconciliationManagerInterface $reconciler */
        $reconciler = $this->container->get(ReconciliationManagerInterface::class);

        $dryRun = isset($options['dry-run']) || isset($options['dryrun']);
        $pillar = isset($options['pillar']) ? (string) $options['pillar'] : null;
        $tenantId = isset($options['tenant']) ? (int) $options['tenant'] : null;

        if ($dryRun) {
            $this->warn("Menjalankan rekonsiliasi dalam mode SIMULASI (--dry-run). Tidak ada perubahan data yang disimpan.");
        } else {
            $this->info("Menjalankan rekonsiliasi aktif dan perbaikan data otomatis...");
        }

        if ($tenantId !== null) {
            $this->line("Filter Tenant ID: {$tenantId}");
        }
        if ($pillar !== null) {
            $this->line("Filter Pilar: {$pillar}");
        }

        try {
            $result = $reconciler->reconcile($tenantId, $pillar, $dryRun);

            $this->line(str_repeat('-', 75));
            $this->line(sprintf("%-30s: %s", "Mode Eksekusi", $result->isDryRun ? "SIMULASI (DRY-RUN)" : "LIVE RECONCILIATION"));
            $this->line(sprintf("%-30s: %d", "Total Diskrepansi Diperbaiki", $result->resolvedCount));
            $this->line(sprintf("%-30s: %d", "Diskrepansi Perlu Review Manual", $result->unresolvedCount));
            $this->line(str_repeat('-', 75));

            if (!empty($result->resolvedByPillar)) {
                $this->line("Rincian Perbaikan per Pilar:");
                foreach ($result->resolvedByPillar as $pKey => $count) {
                    $this->line(sprintf(" - %-15s: %d entitas", strtoupper($pKey), $count));
                }
                $this->line(str_repeat('-', 75));
            }

            if (!empty($result->messages)) {
                $this->line("Log Tindakan:");
                $shown = 0;
                foreach ($result->messages as $msg) {
                    $shown++;
                    if ($shown > 20) {
                        $this->line(sprintf("... dan %d log lainnya.", count($result->messages) - 20));
                        break;
                    }
                    $this->line(" " . $msg);
                }
            }

            if ($result->resolvedCount > 0) {
                $this->success(sprintf(
                    "Proses rekonsiliasi selesai: %d inkonsistensi berhasil disinkronkan ke ledger utama.",
                    $result->resolvedCount
                ));
            } else {
                $this->info("Tidak ada diskrepansi yang memerlukan perbaikan otomatis.");
            }

            return 0;
        } catch (Throwable $e) {
            $this->error("Gagal melakukan rekonsiliasi data: " . $e->getMessage());
            return 1;
        }
    }
}
