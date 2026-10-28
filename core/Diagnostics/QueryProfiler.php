<?php

declare(strict_types=1);

namespace Core\Diagnostics;

class QueryProfiler
{
    private float $slowThresholdMs;
    private int $maxBufferSize;
    private array $slowQueries = [];
    private int $totalQueries = 0;
    private float $totalDurationMs = 0.0;
    private float $maxDurationMs = 0.0;
    private bool $enabled = true;

    public function __construct(float $slowThresholdMs = 25.0, int $maxBufferSize = 100)
    {
        $this->slowThresholdMs = $slowThresholdMs;
        $this->maxBufferSize = $maxBufferSize;
    }

    /**
     * Record an executed SQL query with duration.
     */
    public function record(string $sql, array $params, float $durationMs): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->totalQueries++;
        $this->totalDurationMs += $durationMs;

        if ($durationMs > $this->maxDurationMs) {
            $this->maxDurationMs = $durationMs;
        }

        if ($durationMs >= $this->slowThresholdMs) {
            if (count($this->slowQueries) >= $this->maxBufferSize) {
                array_shift($this->slowQueries);
            }

            $this->slowQueries[] = [
                'sql' => $sql,
                'params' => $params,
                'duration_ms' => round($durationMs, 2),
                'recorded_at' => date('c'),
            ];
        }
    }

    /**
     * Set slow query threshold in milliseconds.
     */
    public function setSlowThreshold(float $thresholdMs): self
    {
        $this->slowThresholdMs = max(0.1, $thresholdMs);
        return $this;
    }

    public function getSlowThreshold(): float
    {
        return $this->slowThresholdMs;
    }

    /**
     * Get list of recorded slow queries.
     */
    public function getSlowQueries(): array
    {
        return $this->slowQueries;
    }

    /**
     * Get aggregate query profiling metrics.
     */
    public function getMetrics(): array
    {
        $avg = $this->totalQueries > 0 ? round($this->totalDurationMs / $this->totalQueries, 2) : 0.0;

        return [
            'total_queries' => $this->totalQueries,
            'slow_queries_count' => count($this->slowQueries),
            'total_duration_ms' => round($this->totalDurationMs, 2),
            'avg_duration_ms' => $avg,
            'max_duration_ms' => round($this->maxDurationMs, 2),
            'slow_threshold_ms' => $this->slowThresholdMs,
        ];
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function reset(): void
    {
        $this->slowQueries = [];
        $this->totalQueries = 0;
        $this->totalDurationMs = 0.0;
        $this->maxDurationMs = 0.0;
    }
}
