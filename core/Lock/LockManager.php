<?php

declare(strict_types=1);

namespace Core\Lock;

use Core\Lock\Drivers\LockDriverInterface;

class LockManager implements LockManagerInterface
{
    public function __construct(
        protected LockDriverInterface $driver
    ) {}

    public function lock(string $resourceKey, int $ttlSeconds = 10, ?string $owner = null): LockInterface
    {
        $owner = $owner ?? $this->generateOwnerToken();
        return new Lock($this->driver, $resourceKey, $ttlSeconds, $owner);
    }

    public function acquire(string $resourceKey, int $ttlSeconds = 10, int $waitTimeoutMs = 0, ?string $owner = null): ?LockInterface
    {
        $lock = $this->lock($resourceKey, $ttlSeconds, $owner);
        if ($lock->acquire($waitTimeoutMs)) {
            return $lock;
        }

        return null;
    }

    public function run(string $resourceKey, callable $callback, int $ttlSeconds = 10, int $waitTimeoutMs = 3000, ?string $owner = null): mixed
    {
        $lock = $this->acquire($resourceKey, $ttlSeconds, $waitTimeoutMs, $owner);
        if ($lock === null) {
            throw new LockTimeoutException($resourceKey, $waitTimeoutMs);
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    public function release(string $resourceKey, ?string $owner = null): bool
    {
        if ($owner !== null) {
            return $this->driver->release($resourceKey, $owner);
        }

        return $this->driver->forceRelease($resourceKey);
    }

    public function isLocked(string $resourceKey): bool
    {
        return $this->driver->isLocked($resourceKey);
    }

    public function pruneExpired(): int
    {
        return $this->driver->pruneExpired();
    }

    public function listActiveLocks(): array
    {
        return $this->driver->listActiveLocks();
    }

    public function getDriver(): LockDriverInterface
    {
        return $this->driver;
    }

    protected function generateOwnerToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
