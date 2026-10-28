<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Resilience\PlatformWatchdogInterface;
use Throwable;

class WatchdogHealthCommand extends Command
{
    protected string $signature = 'watchdog:health';
    protected string $description = 'Inspeksi diagnostik kesehatan dan ketahanan operasional platform real-time';

    public function handle(array $arguments = [], array $options = []): int
    {
        /** @var PlatformWatchdogInterface $watchdog */
        $watchdog = $this->container->get(PlatformWatchdogInterface::class);

        $isJson = isset($options['json']);

        try {
            $report = $watchdog->checkHealth();

            if ($isJson) {
                echo json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
                return $report->isHealthy ? 0 : 1;
            }

            $this->info("Menjalankan audit ketahanan operasional platform (Resilience Watchdog)...");
            $this->line(str_repeat('-', 80));

            $statusColor = match ($report->overallStatus) {
                'healthy' => "\033[32m✔ 100% HEALTHY\033[0m",
                'degraded' => "\033[33m⚠ DEGRADED (PERLU PERHATIAN)\033[0m",
                default => "\033[31m✖ CRITICAL (ANOMALI KRITIS)\033[0m",
            };

            $this->line(sprintf("%-30s: %s", "Status Kesehatan Keseluruhan", $statusColor));
            $this->line(sprintf("%-30s: %s", "Waktu Pengecekan", $report->checkedAt));
            $this->line(sprintf(
                "%-30s: PHP %s | Memori: %s MB (Peak: %s MB)",
                "Informasi Sistem",
                $report->systemInfo['php_version'] ?? 'N/A',
                $report->systemInfo['memory_usage_mb'] ?? 'N/A',
                $report->systemInfo['memory_peak_mb'] ?? 'N/A'
            ));
            $this->line(str_repeat('-', 80));

            $components = [
                'Antrean Latar Belakang (Jobs)' => $report->queueHealth,
                'Circuit Breakers (Fail-Fast)' => $report->circuitHealth,
                'Basis Data SQLite & WAL' => $report->databaseHealth,
                'Atomic Locks & Concurrency' => $report->lockHealth,
                'Dead-Letter Queue (DLQ)' => $report->dlqHealth,
            ];

            foreach ($components as $title => $comp) {
                $compStatus = match ($comp['status']) {
                    'healthy' => "\033[32m[OK]\033[0m",
                    'degraded' => "\033[33m[DEGRADED]\033[0m",
                    default => "\033[31m[CRITICAL]\033[0m",
                };

                $this->line(sprintf(" %s %-32s: %s", $compStatus, $title, $comp['message']));
            }

            $this->line(str_repeat('-', 80));

            return $report->isHealthy ? 0 : 1;
        } catch (Throwable $e) {
            $this->error("Gagal menjalankan watchdog health check: " . $e->getMessage());
            return 1;
        }
    }
}
