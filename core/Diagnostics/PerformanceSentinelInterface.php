<?php

declare(strict_types=1);

namespace Core\Diagnostics;

interface PerformanceSentinelInterface
{
    /**
     * Get the SQL query profiler instance.
     */
    public function getQueryProfiler(): QueryProfiler;

    /**
     * Get the memory sentinel instance.
     */
    public function getMemorySentinel(): MemorySentinel;

    /**
     * Get real-time system performance diagnostic metrics.
     */
    public function getMetrics(): array;

    /**
     * Run an SQLite throughput & lock latency benchmark.
     */
    public function runBenchmark(int $iterations = 100): array;

    /**
     * Clear all recorded performance metrics and slow query logs.
     */
    public function reset(): void;
}
