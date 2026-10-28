<?php

declare(strict_types=1);

namespace Core\Resilience;

use Throwable;

interface CircuitBreakerInterface
{
    public const STATE_CLOSED = 'closed';
    public const STATE_OPEN = 'open';
    public const STATE_HALF_OPEN = 'half_open';

    public function getServiceName(): string;

    /**
     * Get current state ('closed', 'open', 'half_open').
     */
    public function getState(): string;

    /**
     * Check if service calls are permitted.
     */
    public function isAvailable(): bool;

    /**
     * Execute an action protected by the circuit breaker with optional fallback.
     *
     * @template T
     * @param callable(): T $action
     * @param (callable(Throwable): T)|null $fallback
     * @return T
     * @throws Throwable
     */
    public function execute(callable $action, ?callable $fallback = null): mixed;

    public function recordSuccess(): void;

    public function recordFailure(Throwable $e): void;

    /**
     * Manually reset circuit breaker back to CLOSED.
     */
    public function reset(): void;

    /**
     * @return array<string, mixed>
     */
    public function getStats(): array;
}
