<?php

declare(strict_types=1);

namespace Core\Resilience;

class PlatformHealthReport
{
    /**
     * @param array{status: string, message: string, details?: array<string, mixed>} $queueHealth
     * @param array{status: string, message: string, details?: array<string, mixed>} $circuitHealth
     * @param array{status: string, message: string, details?: array<string, mixed>} $databaseHealth
     * @param array{status: string, message: string, details?: array<string, mixed>} $lockHealth
     * @param array{status: string, message: string, details?: array<string, mixed>} $dlqHealth
     * @param array<string, mixed> $systemInfo
     */
    public function __construct(
        public readonly bool $isHealthy,
        public readonly string $overallStatus, // 'healthy', 'degraded', 'critical'
        public readonly array $queueHealth,
        public readonly array $circuitHealth,
        public readonly array $databaseHealth,
        public readonly array $lockHealth,
        public readonly array $dlqHealth,
        public readonly array $systemInfo,
        public readonly string $checkedAt
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'is_healthy' => $this->isHealthy,
            'overall_status' => $this->overallStatus,
            'components' => [
                'queue' => $this->queueHealth,
                'circuit_breakers' => $this->circuitHealth,
                'database' => $this->databaseHealth,
                'locks' => $this->lockHealth,
                'dead_letter_queue' => $this->dlqHealth,
            ],
            'system_info' => $this->systemInfo,
            'checked_at' => $this->checkedAt,
        ];
    }
}
