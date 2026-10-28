<?php

declare(strict_types=1);

namespace Core\Lock;

use Core\Lock\Drivers\LockDriverInterface;

class Lock implements LockInterface
{
    protected bool $acquired = false;

    public function __construct(
        protected LockDriverInterface $driver,
        protected string $key,
        protected int $ttlSeconds,
        protected string $owner
    ) {}

    public function acquire(int $waitTimeoutMs = 0): bool
    {
        $this->acquired = $this->driver->acquire($this->key, $this->owner, $this->ttlSeconds, $waitTimeoutMs);
        return $this->acquired;
    }

    public function release(): bool
    {
        if ($this->driver->release($this->key, $this->owner)) {
            $this->acquired = false;
            return true;
        }

        return false;
    }

    public function forceRelease(): bool
    {
        $this->acquired = false;
        return $this->driver->forceRelease($this->key);
    }

    public function isAcquired(): bool
    {
        return $this->acquired && $this->driver->getOwner($this->key) === $this->owner;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getOwner(): string
    {
        return $this->owner;
    }

    public function getTtl(): int
    {
        return $this->ttlSeconds;
    }
}
