<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Diagnostics\PerformanceSentinelInterface;

class SentinelProfileCommand extends Command
{
    protected string $signature = 'sentinel:profile';
    protected string $description = 'Run an SQLite WAL throughput and lock latency micro-benchmark';

    public function handle(array $arguments = [], array $options = []): int
    {
        $iterations = isset($options['iterations']) ? (int) $options['iterations'] : 100;
        $this->info("Menjalankan micro-benchmark throughput SQLite & latensi lock ({$iterations} iterasi)...");

        try {
            /** @var PerformanceSentinelInterface $sentinel */
            $sentinel = $this->container->get(PerformanceSentinelInterface::class);
            $bench = $sentinel->runBenchmark($iterations);

            $this->line(str_repeat('=', 70));
            $this->line(" HASIL BENCHMARK RUNTIME SQLITE");
            $this->line(str_repeat('=', 70));
            $this->line(sprintf("Iterasi Pengujian      : %d operasi", $bench['iterations']));
            $this->line(sprintf("Write Throughput      : %s ops/sec (Total: %s ms, Avg: %s ms/op)", 
                number_format($bench['write']['operations_per_sec']), 
                $bench['write']['total_time_ms'], 
                $bench['write']['avg_time_per_op_ms']
            ));
            $this->line(sprintf("Read Throughput       : %s ops/sec (Total: %s ms, Avg: %s ms/op)", 
                number_format($bench['read']['operations_per_sec']), 
                $bench['read']['total_time_ms'], 
                $bench['read']['avg_time_per_op_ms']
            ));
            $this->line(sprintf("Lock Acquire Latency  : %s ms (Rata-rata 10 siklus transaksi)", $bench['lock_latency_ms']));
            $this->line(str_repeat('=', 70));

            $this->success("Benchmark SQLite selesai dengan sukses.");
            return 0;
        } catch (\Throwable $e) {
            $this->error("Gagal menjalankan benchmark: " . $e->getMessage());
            return 1;
        }
    }
}
