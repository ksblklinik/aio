<?php

declare(strict_types=1);

namespace Core\Diagnostics;

class MemorySentinel
{
    private int $warningThresholdBytes;

    public function __construct(int $warningThresholdBytes = 67108864) // 64 MB default
    {
        $this->warningThresholdBytes = $warningThresholdBytes;
    }

    public function getCurrentUsage(): int
    {
        return memory_get_usage(true);
    }

    public function getPeakUsage(): int
    {
        return memory_get_peak_usage(true);
    }

    public function getMemoryLimit(): int
    {
        $limitStr = trim(ini_get('memory_limit') ?: '128M');
        if ($limitStr === '-1') {
            return PHP_INT_MAX;
        }

        $unit = strtolower(substr($limitStr, -1));
        $value = (int) substr($limitStr, 0, -1);

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => (int) $limitStr,
        };
    }

    public function setWarningThreshold(int $bytes): self
    {
        $this->warningThresholdBytes = max(1048576, $bytes); // At least 1 MB
        return $this;
    }

    public function getWarningThreshold(): int
    {
        return $this->warningThresholdBytes;
    }

    public function isThresholdExceeded(): bool
    {
        return $this->getPeakUsage() >= $this->warningThresholdBytes;
    }

    public function getMetrics(): array
    {
        $current = $this->getCurrentUsage();
        $peak = $this->getPeakUsage();
        $limit = $this->getMemoryLimit();
        $percent = $limit > 0 && $limit !== PHP_INT_MAX ? round(($current / $limit) * 100, 2) : 0.0;

        return [
            'current_usage_bytes' => $current,
            'current_usage_formatted' => $this->formatBytes($current),
            'peak_usage_bytes' => $peak,
            'peak_usage_formatted' => $this->formatBytes($peak),
            'limit_bytes' => $limit,
            'limit_formatted' => $limit === PHP_INT_MAX ? 'Unlimited' : $this->formatBytes($limit),
            'usage_percentage' => $percent,
            'threshold_bytes' => $this->warningThresholdBytes,
            'threshold_formatted' => $this->formatBytes($this->warningThresholdBytes),
            'is_threshold_exceeded' => $this->isThresholdExceeded(),
        ];
    }

    public function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
}
