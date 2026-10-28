<?php

declare(strict_types=1);

namespace Core\Diagnostics;

use Core\Database\Connection;

class PerformanceSentinel implements PerformanceSentinelInterface
{
    private Connection $db;
    private QueryProfiler $queryProfiler;
    private MemorySentinel $memorySentinel;

    public function __construct(
        Connection $db,
        ?QueryProfiler $queryProfiler = null,
        ?MemorySentinel $memorySentinel = null
    ) {
        $this->db = $db;
        $this->queryProfiler = $queryProfiler ?? new QueryProfiler();
        $this->memorySentinel = $memorySentinel ?? new MemorySentinel();
    }

    public function getQueryProfiler(): QueryProfiler
    {
        return $this->queryProfiler;
    }

    public function getMemorySentinel(): MemorySentinel
    {
        return $this->memorySentinel;
    }

    /**
     * Get real-time system performance diagnostic metrics.
     */
    public function getMetrics(): array
    {
        $dbPath = (string) config('database.connections.sqlite.database', 'database.sqlite');
        $walPath = $dbPath . '-wal';
        $shmPath = $dbPath . '-shm';

        $dbSize = file_exists($dbPath) ? filesize($dbPath) : 0;
        $walSize = file_exists($walPath) ? filesize($walPath) : 0;
        $shmSize = file_exists($shmPath) ? filesize($shmPath) : 0;

        $journalMode = 'unknown';
        try {
            $jm = $this->db->selectOne("PRAGMA journal_mode;");
            $journalMode = (string) ($jm['journal_mode'] ?? 'unknown');
        } catch (\Throwable $e) {
            // In-memory or restricted
        }

        return [
            'timestamp' => date('c'),
            'php_version' => PHP_VERSION,
            'database' => [
                'journal_mode' => $journalMode,
                'db_file_bytes' => $dbSize,
                'db_file_formatted' => $this->memorySentinel->formatBytes((int) $dbSize),
                'wal_file_bytes' => $walSize,
                'wal_file_formatted' => $this->memorySentinel->formatBytes((int) $walSize),
                'shm_file_bytes' => $shmSize,
                'shm_file_formatted' => $this->memorySentinel->formatBytes((int) $shmSize),
            ],
            'queries' => $this->queryProfiler->getMetrics(),
            'memory' => $this->memorySentinel->getMetrics(),
        ];
    }

    /**
     * Run an SQLite throughput & lock latency benchmark.
     */
    public function runBenchmark(int $iterations = 100): array
    {
        $iterations = max(10, min(5000, $iterations));
        $benchmarkTable = '_temp_benchmark_' . bin2hex(random_bytes(4));

        try {
            // 1. Setup temporary table (does not write to main persistent pages)
            $this->db->statement("CREATE TEMPORARY TABLE {$benchmarkTable} (id INTEGER PRIMARY KEY AUTOINCREMENT, val TEXT, num REAL);");

            // 2. Measure Write Throughput (batched transaction)
            $writeStart = microtime(true);
            $this->db->statement("BEGIN IMMEDIATE TRANSACTION;");
            for ($i = 0; $i < $iterations; $i++) {
                $this->db->statement(
                    "INSERT INTO {$benchmarkTable} (val, num) VALUES (?, ?)",
                    ['bench_' . $i, (float) ($i * 1.5)]
                );
            }
            $this->db->statement("COMMIT;");
            $writeDuration = microtime(true) - $writeStart;
            $writeDurationMs = $writeDuration * 1000.0;
            $writesPerSec = $writeDuration > 0 ? round($iterations / $writeDuration) : 0;

            // 3. Measure Read Throughput
            $readStart = microtime(true);
            for ($i = 0; $i < $iterations; $i++) {
                $this->db->selectOne("SELECT * FROM {$benchmarkTable} WHERE id = ?", [($i % $iterations) + 1]);
            }
            $readDuration = microtime(true) - $readStart;
            $readDurationMs = $readDuration * 1000.0;
            $readsPerSec = $readDuration > 0 ? round($iterations / $readDuration) : 0;

            // 4. Measure Lock Acquisition Latency
            $lockLatencies = [];
            for ($i = 0; $i < 10; $i++) {
                $lockStart = microtime(true);
                $this->db->statement("BEGIN IMMEDIATE TRANSACTION;");
                $this->db->statement("UPDATE {$benchmarkTable} SET num = num + 1 WHERE id = 1");
                $this->db->statement("COMMIT;");
                $lockLatencies[] = (microtime(true) - $lockStart) * 1000.0;
            }
            $avgLockLatencyMs = round(array_sum($lockLatencies) / count($lockLatencies), 3);

            return [
                'iterations' => $iterations,
                'write' => [
                    'total_time_ms' => round($writeDurationMs, 2),
                    'operations_per_sec' => $writesPerSec,
                    'avg_time_per_op_ms' => round($writeDurationMs / $iterations, 3),
                ],
                'read' => [
                    'total_time_ms' => round($readDurationMs, 2),
                    'operations_per_sec' => $readsPerSec,
                    'avg_time_per_op_ms' => round($readDurationMs / $iterations, 3),
                ],
                'lock_latency_ms' => $avgLockLatencyMs,
            ];
        } finally {
            try {
                $this->db->statement("DROP TABLE IF EXISTS {$benchmarkTable};");
            } catch (\Throwable) {
                // Ignore cleanup error
            }
        }
    }

    public function reset(): void
    {
        $this->queryProfiler->reset();
    }
}
