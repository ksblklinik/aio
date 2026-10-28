<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Diagnostics\PerformanceSentinelInterface;

class SentinelSlowQueriesCommand extends Command
{
    protected string $signature = 'sentinel:slow-queries';
    protected string $description = 'Display captured slow queries exceeding the performance threshold';

    public function handle(array $arguments = [], array $options = []): int
    {
        /** @var PerformanceSentinelInterface $sentinel */
        $sentinel = $this->container->get(PerformanceSentinelInterface::class);
        $profiler = $sentinel->getQueryProfiler();
        $slowQueries = $profiler->getSlowQueries();
        $metrics = $profiler->getMetrics();

        $this->line(str_repeat('=', 70));
        $this->line(" DAFTAR QUERY LAMBAT (SLOW QUERIES BUFFER)");
        $this->line(str_repeat('=', 70));
        $this->line(sprintf("Threshold Lambat : %s ms", $metrics['slow_threshold_ms']));
        $this->line(sprintf("Total Eksekusi   : %d query", $metrics['total_queries']));
        $this->line(sprintf("Total Terdeteksi : %d query lambat", $metrics['slow_queries_count']));
        $this->line(str_repeat('-', 70));

        if (empty($slowQueries)) {
            $this->success("Tidak ada query lambat yang melampaui ambang batas {$metrics['slow_threshold_ms']} ms.");
            return 0;
        }

        foreach ($slowQueries as $idx => $q) {
            $num = $idx + 1;
            $this->warn("[#{$num}] Durasi: {$q['duration_ms']} ms | Waktu: {$q['recorded_at']}");
            $this->line("SQL: " . $q['sql']);
            if (!empty($q['params'])) {
                $this->line("Params: " . json_encode($q['params']));
            }
            $this->line(str_repeat('-', 70));
        }

        return 0;
    }
}
