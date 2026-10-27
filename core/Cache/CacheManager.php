<?php

declare(strict_types=1);

namespace Core\Cache;

use Core\Database\TenantContext;

class CacheManager
{
    protected ?TenantContext $tenantContext;
    protected string $defaultDriver;
    /** @var array<string, CacheDriverInterface> */
    protected array $drivers = [];

    public function __construct(
        CacheDriverInterface $defaultDriverInstance,
        ?TenantContext $tenantContext = null,
        string $defaultDriver = 'file'
    ) {
        $this->tenantContext = $tenantContext;
        $this->defaultDriver = $defaultDriver;
        $this->drivers[$defaultDriver] = $defaultDriverInstance;
    }

    public function driver(?string $name = null): CacheDriverInterface
    {
        $driverName = $name ?: $this->defaultDriver;

        if (!isset($this->drivers[$driverName])) {
            throw new \InvalidArgumentException("Cache driver [{$driverName}] tidak terdaftar.");
        }

        return $this->drivers[$driverName];
    }

    public function setDriver(string $name, CacheDriverInterface $driver): self
    {
        $this->drivers[$name] = $driver;
        return $this;
    }

    public function get(string $key, mixed $default = null, bool $global = false): mixed
    {
        $prefixedKey = $this->resolveKey($key, $global);
        return $this->driver()->get($prefixedKey, $default);
    }

    public function put(string $key, mixed $value, ?int $ttl = null, bool $global = false): bool
    {
        $prefixedKey = $this->resolveKey($key, $global);
        return $this->driver()->put($prefixedKey, $value, $ttl);
    }

    public function has(string $key, bool $global = false): bool
    {
        $prefixedKey = $this->resolveKey($key, $global);
        return $this->driver()->has($prefixedKey);
    }

    public function forget(string $key, bool $global = false): bool
    {
        $prefixedKey = $this->resolveKey($key, $global);
        return $this->driver()->forget($prefixedKey);
    }

    public function flush(): bool
    {
        return $this->driver()->flush();
    }

    /**
     * Dapatkan item dari cache, atau eksekusi callback dan simpan hasilnya jika belum ada.
     */
    public function remember(string $key, int $ttl, callable $callback, bool $global = false): mixed
    {
        $prefixedKey = $this->resolveKey($key, $global);
        $value = $this->driver()->get($prefixedKey);

        if ($value !== null) {
            return $value;
        }

        $freshValue = $callback();
        $this->driver()->put($prefixedKey, $freshValue, $ttl);

        return $freshValue;
    }

    /**
     * Resolusi prefix key dengan tenant context untuk mencegah cross-tenant data leak.
     */
    public function resolveKey(string $key, bool $global = false): string
    {
        if ($global || str_starts_with($key, 'global:')) {
            return $key;
        }

        $tenantId = null;
        if ($this->tenantContext !== null) {
            $tenantId = $this->tenantContext->getTenantId();
        }

        $prefix = $tenantId !== null ? "t_{$tenantId}" : "t_0";
        return "{$prefix}:{$key}";
    }
}
