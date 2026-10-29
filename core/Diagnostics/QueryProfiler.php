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
    private ?string $storagePath = null;

    private const SENSITIVE_KEYWORDS = [
        'password', 'passwd', 'secret', 'token', 'access_token', 'refresh_token',
        'api_key', 'apikey', 'auth', 'authorization', 'pin', 'cvv', 'cvc',
        'card_number', 'nik', 'credit_card', 'private_key', 'hash'
    ];

    public function __construct(
        float $slowThresholdMs = 25.0,
        int $maxBufferSize = 100,
        ?string $storagePath = null
    ) {
        $this->slowThresholdMs = $slowThresholdMs;
        $this->maxBufferSize = $maxBufferSize;
        $this->storagePath = $storagePath ?? (function_exists('storage_path') ? storage_path('diagnostics/slow_queries.json') : null);
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
            $redactedParams = $this->redactParams($params, $sql);

            if (count($this->slowQueries) >= $this->maxBufferSize) {
                array_shift($this->slowQueries);
            }

            $entry = [
                'sql' => $sql,
                'params' => $redactedParams,
                'duration_ms' => round($durationMs, 2),
                'recorded_at' => date('c'),
            ];

            $this->slowQueries[] = $entry;
            $this->persistSlowQuery($entry);
        }
    }

    /**
     * Redact sensitive fields/PII from query parameters.
     */
    public function redactParams(array $params, string $sql = ''): array
    {
        if (empty($params)) {
            return [];
        }

        // 1. Detect positional column mapping if available
        $positionalColumns = $this->extractPositionalColumns($sql);

        $redacted = [];
        $index = 0;
        foreach ($params as $key => $value) {
            // Check value first for specialized redaction tags (HASH, TOKEN, NIK, EMAIL, etc.)
            $redactedVal = $this->redactValue($value);

            // If value was not replaced by a specialized tag, check if key or positional column is sensitive
            if ($redactedVal === $value) {
                if (is_string($key)) {
                    $cleanKey = ltrim($key, ':');
                    if ($this->isKeySensitive($cleanKey)) {
                        $redacted[$key] = '[REDACTED]';
                        $index++;
                        continue;
                    }
                } elseif (isset($positionalColumns[$index]) && $this->isKeySensitive($positionalColumns[$index])) {
                    $redacted[$key] = '[REDACTED]';
                    $index++;
                    continue;
                }
            }

            $redacted[$key] = $redactedVal;
            $index++;
        }

        return $redacted;
    }

    /**
     * Extract column names matching positional '?' placeholders in SQL.
     */
    private function extractPositionalColumns(string $sql): array
    {
        if ($sql === '' || !str_contains($sql, '?')) {
            return [];
        }

        // Pattern 1: INSERT INTO table (col1, col2, ...) VALUES (?, ?, ...)
        if (preg_match('/INSERT\s+INTO\s+[`"]?[a-zA-Z0-9_]+[`"]?\s*\(([^)]+)\)\s*VALUES/i', $sql, $matches)) {
            $cols = explode(',', $matches[1]);
            return array_map(fn($c) => trim(str_replace(['`', '"', ' '], '', $c)), $cols);
        }

        // Pattern 2: col = ?, col LIKE ?, col IN (?)
        if (preg_match_all('/[`"]?([a-zA-Z0-9_]+)[`"]?\s*(?:=|<|>|<=|>=|LIKE|ILIKE)\s*\?/i', $sql, $matches)) {
            return $matches[1];
        }

        return [];
    }

    private function isKeySensitive(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::SENSITIVE_KEYWORDS as $keyword) {
            if (str_contains($lower, $keyword)) {
                return true;
            }
        }
        return false;
    }

    private function redactValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->redactParams($value);
        }

        if (!is_string($value)) {
            return $value;
        }

        // Check password hashes ($2y$..., $2a$..., $argon2...)
        if (str_starts_with($value, '$2y$') || str_starts_with($value, '$2a$') || str_starts_with($value, '$argon2')) {
            return '[REDACTED:HASH]';
        }

        // Check JWT token
        if (str_starts_with($value, 'eyJ') && substr_count($value, '.') === 2) {
            return '[REDACTED:TOKEN]';
        }

        // Check Indonesian NIK (16 contiguous digits)
        if (preg_match('/^\d{16}$/', $value)) {
            return '[REDACTED:NIK]';
        }

        // Check Email
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $parts = explode('@', $value, 2);
            $name = $parts[0];
            $maskedName = mb_substr($name, 0, 1) . '***';
            return $maskedName . '@' . $parts[1];
        }

        // Check Bearer authorization
        if (stripos($value, 'bearer ') === 0) {
            return 'Bearer [REDACTED]';
        }

        return $value;
    }

    /**
     * Persist slow query entry into bounded JSON ring-buffer on disk.
     */
    private function persistSlowQuery(array $entry): void
    {
        if (!$this->storagePath) {
            return;
        }

        try {
            $dir = dirname($this->storagePath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            $fp = @fopen($this->storagePath, 'c+');
            if (!$fp) {
                return;
            }

            if (flock($fp, LOCK_EX)) {
                $content = stream_get_contents($fp);
                $items = [];
                if ($content !== false && trim($content) !== '') {
                    $decoded = json_decode($content, true);
                    if (is_array($decoded)) {
                        $items = $decoded;
                    }
                }

                $items[] = $entry;
                if (count($items) > $this->maxBufferSize) {
                    $items = array_slice($items, -$this->maxBufferSize);
                }

                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                fflush($fp);
                flock($fp, LOCK_UN);
            }

            fclose($fp);
        } catch (\Throwable) {
            // Silent fallback to avoid interrupting runtime request
        }
    }

    public function setStoragePath(?string $path): self
    {
        $this->storagePath = $path;
        return $this;
    }

    public function getStoragePath(): ?string
    {
        return $this->storagePath;
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
     * Get list of recorded slow queries (from persistent ring-buffer or memory).
     */
    public function getSlowQueries(bool $includePersistent = true): array
    {
        if ($includePersistent && $this->storagePath && file_exists($this->storagePath)) {
            try {
                $content = @file_get_contents($this->storagePath);
                if ($content !== false && trim($content) !== '') {
                    $decoded = json_decode($content, true);
                    if (is_array($decoded) && !empty($decoded)) {
                        return $decoded;
                    }
                }
            } catch (\Throwable) {
            }
        }

        return $this->slowQueries;
    }

    /**
     * Get aggregate query profiling metrics.
     */
    public function getMetrics(): array
    {
        $avg = $this->totalQueries > 0 ? round($this->totalDurationMs / $this->totalQueries, 2) : 0.0;
        $slowQueries = $this->getSlowQueries(true);

        return [
            'total_queries' => $this->totalQueries,
            'slow_queries_count' => count($slowQueries),
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

        if ($this->storagePath && file_exists($this->storagePath)) {
            @unlink($this->storagePath);
        }
    }
}
