<?php

declare(strict_types=1);

namespace Core\Resilience\Exceptions;

use RuntimeException;

class CircuitBreakerOpenException extends RuntimeException
{
    public function __construct(
        private string $serviceName,
        private int $cooldownRemainingSeconds = 0,
        ?string $lastError = null
    ) {
        $message = sprintf(
            "Circuit breaker untuk layanan '%s' sedang berstatus TERBUKA (OPEN - Fail-Fast). Tunggu %d detik untuk probe pemulihan.%s",
            $serviceName,
            $cooldownRemainingSeconds,
            $lastError ? " Penyebab terakhir: {$lastError}" : ""
        );
        parent::__construct($message, 503);
    }

    public function getServiceName(): string
    {
        return $this->serviceName;
    }

    public function getCooldownRemainingSeconds(): int
    {
        return $this->cooldownRemainingSeconds;
    }
}
