<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Diagnostics\PerformanceSentinelInterface;

class SentinelStatusCommand extends Command
{
    protected string $signature = 'sentinel:status';
    protected string $description = 'Display real-time runtime performance, memory health, and SQLite metrics';

    public function handle(array $arguments = [], array $options = []): int
    {
        /** @var PerformanceSentinelInterface $sentinel */
        $sentinel = $this->container->get(PerformanceSentinelInterface::class);
        $metrics = $sentinel->getMetrics();

        $this->line(str_repeat('=', 70));
        $this->line(" MONITOR RUNTIME SENTINEL & STATUS PERFORMA PLATFORM");
        $this->line(str_repeat('=', 70));
        $this->line("Waktu Diagnosa        : " . $metrics['timestamp']);
        $this->line("Versi PHP Runtime     : " . $metrics['php_version']);
        $this->line(str_repeat('-', 70));

        // Memory Metrics
        $mem = $metrics['memory'];
        $this->line(" ALOKASI MEMORI RUNTIME");
        $this->line(sprintf(" - Penggunaan Sekarang : %s", $mem['current_usage_formatted']));
        $this->line(sprintf(" - Puncak (Peak)       : %s", $mem['peak_usage_formatted']));
        $this->line(sprintf(" - Batas Memori (Limit): %s", $mem['limit_formatted']));
        $this->line(sprintf(" - Ambang Peringatan   : %s", $mem['threshold_formatted']));
        if ($mem['is_threshold_exceeded']) {
            $this->warn(" [!] PERINGATAN: Penggunaan memori puncak melampaui ambang peringatan.");
        } else {
            $this->success(" [✓] Alokasi memori sehat dalam batas wajar.");
        }
        $this->line(str_repeat('-', 70));

        // Database Metrics
        $db = $metrics['database'];
        $this->line(" KESEHATAN BERKAS SQLITE");
        $this->line(sprintf(" - Mode Journal        : %s", strtoupper((string) $db['journal_mode'])));
        $this->line(sprintf(" - Ukuran DB Utama     : %s (%d bytes)", $db['db_file_formatted'], $db['db_file_bytes']));
        $this->line(sprintf(" - Ukuran WAL File     : %s (%d bytes)", $db['wal_file_formatted'], $db['wal_file_bytes']));
        $this->line(sprintf(" - Ukuran SHM File     : %s (%d bytes)", $db['shm_file_formatted'], $db['shm_file_bytes']));
        $this->line(str_repeat('-', 70));

        // Query Metrics
        $q = $metrics['queries'];
        $this->line(" METRIK PROFILING QUERY");
        $this->line(sprintf(" - Total Query         : %d query", $q['total_queries']));
        $this->line(sprintf(" - Query Lambat        : %d query", $q['slow_queries_count']));
        $this->line(sprintf(" - Total Durasi        : %s ms", $q['total_duration_ms']));
        $this->line(sprintf(" - Rata-rata Durasi    : %s ms", $q['avg_duration_ms']));
        $this->line(sprintf(" - Durasi Maksimal     : %s ms", $q['max_duration_ms']));
        $this->line(str_repeat('=', 70));

        return 0;
    }
}
