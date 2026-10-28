<?php

declare(strict_types=1);

namespace Core\Resilience;

interface PlatformWatchdogInterface
{
    /**
     * Perform deep operational resilience health inspection across all subsystems.
     */
    public function checkHealth(): PlatformHealthReport;

    /**
     * Check if the overall platform resilience is healthy.
     */
    public function isHealthy(): bool;
}
