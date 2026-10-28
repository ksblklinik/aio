<?php

declare(strict_types=1);

namespace Core\Resilience;

use Core\Database\Connection;
use Core\Resilience\Exceptions\CircuitBreakerOpenException;
use Throwable;

class DatabaseCircuitBreaker implements CircuitBreakerInterface
{
    public function __construct(
        private Connection $db,
        private string $serviceName,
        private int $failureThreshold = 5,
        private int $cooldownSeconds = 60,
        private int $successThreshold = 2
    ) {
    }

    public function getServiceName(): string
    {
        return $this->serviceName;
    }

    public function getState(): string
    {
        $row = $this->getOrInitRecord();

        $currentState = (string) $row['state'];

        if ($currentState === self::STATE_OPEN) {
            $lastChange = strtotime((string) $row['last_state_change_at']);
            $cooldown = (int) ($row['cooldown_seconds'] ?? $this->cooldownSeconds);

            if ((time() - $lastChange) >= $cooldown) {
                // Cooldown expired, transition to HALF_OPEN for probe
                $this->transitionTo(self::STATE_HALF_OPEN);
                return self::STATE_HALF_OPEN;
            }
        }

        return $currentState;
    }

    public function isAvailable(): bool
    {
        return $this->getState() !== self::STATE_OPEN;
    }

    public function execute(callable $action, ?callable $fallback = null): mixed
    {
        $state = $this->getState();

        if ($state === self::STATE_OPEN) {
            $row = $this->getOrInitRecord();
            $lastChange = strtotime((string) $row['last_state_change_at']);
            $cooldown = (int) ($row['cooldown_seconds'] ?? $this->cooldownSeconds);
            $remaining = max(0, $cooldown - (time() - $lastChange));
            $lastError = $row['last_error'] ?? null;

            $openException = new CircuitBreakerOpenException($this->serviceName, $remaining, $lastError);

            if ($fallback !== null) {
                return $fallback($openException);
            }

            throw $openException;
        }

        try {
            $result = $action();
            $this->recordSuccess();
            return $result;
        } catch (Throwable $e) {
            $this->recordFailure($e);

            if ($fallback !== null) {
                return $fallback($e);
            }

            throw $e;
        }
    }

    public function recordSuccess(): void
    {
        $row = $this->getOrInitRecord();
        $currentState = (string) $row['state'];
        $now = date('Y-m-d H:i:s');

        if ($currentState === self::STATE_HALF_OPEN) {
            $newSuccessCount = ((int) $row['success_count']) + 1;
            if ($newSuccessCount >= $this->successThreshold) {
                // Service recovered, close circuit
                $this->db->statement("
                    UPDATE circuit_breakers 
                    SET state = ?, failure_count = 0, success_count = 0, last_success_at = ?, last_state_change_at = ?, updated_at = ?
                    WHERE service_name = ?
                ", [self::STATE_CLOSED, $now, $now, $now, $this->serviceName]);
            } else {
                $this->db->statement("
                    UPDATE circuit_breakers 
                    SET success_count = ?, last_success_at = ?, updated_at = ?
                    WHERE service_name = ?
                ", [$newSuccessCount, $now, $now, $this->serviceName]);
            }
        } elseif ($currentState === self::STATE_CLOSED) {
            // Reset any transient failure count on success
            $this->db->statement("
                UPDATE circuit_breakers 
                SET failure_count = 0, last_success_at = ?, updated_at = ?
                WHERE service_name = ?
            ", [$now, $now, $this->serviceName]);
        }
    }

    public function recordFailure(Throwable $e): void
    {
        $row = $this->getOrInitRecord();
        $currentState = (string) $row['state'];
        $now = date('Y-m-d H:i:s');
        $errorMsg = mb_substr($e->getMessage(), 0, 500);

        if ($currentState === self::STATE_HALF_OPEN) {
            // Probe failed, immediately trip back to OPEN
            $this->db->statement("
                UPDATE circuit_breakers 
                SET state = ?, failure_count = failure_threshold, success_count = 0, last_failure_at = ?, last_state_change_at = ?, last_error = ?, updated_at = ?
                WHERE service_name = ?
            ", [self::STATE_OPEN, $now, $now, $errorMsg, $now, $this->serviceName]);
        } elseif ($currentState === self::STATE_CLOSED) {
            $newFailureCount = ((int) $row['failure_count']) + 1;
            $threshold = (int) ($row['failure_threshold'] ?? $this->failureThreshold);

            if ($newFailureCount >= $threshold) {
                // Trip to OPEN
                $this->db->statement("
                    UPDATE circuit_breakers 
                    SET state = ?, failure_count = ?, success_count = 0, last_failure_at = ?, last_state_change_at = ?, last_error = ?, updated_at = ?
                    WHERE service_name = ?
                ", [self::STATE_OPEN, $newFailureCount, $now, $now, $errorMsg, $now, $this->serviceName]);
            } else {
                $this->db->statement("
                    UPDATE circuit_breakers 
                    SET failure_count = ?, last_failure_at = ?, last_error = ?, updated_at = ?
                    WHERE service_name = ?
                ", [$newFailureCount, $now, $errorMsg, $now, $this->serviceName]);
            }
        }
    }

    public function reset(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->statement("
            UPDATE circuit_breakers 
            SET state = ?, failure_count = 0, success_count = 0, last_error = NULL, last_state_change_at = ?, updated_at = ?
            WHERE service_name = ?
        ", [self::STATE_CLOSED, $now, $now, $this->serviceName]);
    }

    public function getStats(): array
    {
        $row = $this->getOrInitRecord();
        $lastChange = strtotime((string) $row['last_state_change_at']);
        $cooldown = (int) ($row['cooldown_seconds'] ?? $this->cooldownSeconds);
        $remaining = $row['state'] === self::STATE_OPEN ? max(0, $cooldown - (time() - $lastChange)) : 0;

        return [
            'service_name' => $this->serviceName,
            'state' => $this->getState(),
            'failure_count' => (int) $row['failure_count'],
            'success_count' => (int) $row['success_count'],
            'failure_threshold' => (int) $row['failure_threshold'],
            'cooldown_seconds' => $cooldown,
            'cooldown_remaining' => $remaining,
            'last_failure_at' => $row['last_failure_at'],
            'last_success_at' => $row['last_success_at'],
            'last_state_change_at' => $row['last_state_change_at'],
            'last_error' => $row['last_error'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getOrInitRecord(): array
    {
        $row = $this->db->selectOne(
            "SELECT * FROM circuit_breakers WHERE service_name = ?",
            [$this->serviceName]
        );

        if ($row) {
            return $row;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->statement("
            INSERT INTO circuit_breakers (
                service_name, state, failure_count, success_count,
                failure_threshold, cooldown_seconds, last_state_change_at, created_at, updated_at
            ) VALUES (?, ?, 0, 0, ?, ?, ?, ?, ?)
        ", [
            $this->serviceName,
            self::STATE_CLOSED,
            $this->failureThreshold,
            $this->cooldownSeconds,
            $now,
            $now,
            $now,
        ]);

        return $this->db->selectOne(
            "SELECT * FROM circuit_breakers WHERE service_name = ?",
            [$this->serviceName]
        ) ?? [];
    }

    private function transitionTo(string $state): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->statement("
            UPDATE circuit_breakers 
            SET state = ?, success_count = 0, last_state_change_at = ?, updated_at = ?
            WHERE service_name = ?
        ", [$state, $now, $now, $this->serviceName]);
    }
}
