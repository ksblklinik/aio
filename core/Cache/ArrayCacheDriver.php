<?php

declare(strict_types=1);

namespace Core\Cache;

class ArrayCacheDriver implements CacheDriverInterface
{
    /** @var array<string, array{value: mixed, expires_at: int|null}> */
    protected array $storage = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (!isset($this->storage[$key])) {
            return $default;
        }

        $item = $this->storage[$key];
        if ($item['expires_at'] !== null && $item['expires_at'] <= time()) {
            unset($this->storage[$key]);
            return $default;
        }

        return $item['value'];
    }

    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        $expiresAt = ($ttl !== null) ? (time() + $ttl) : null;
        $this->storage[$key] = [
            'value' => $value,
            'expires_at' => $expiresAt,
        ];
        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function forget(string $key): bool
    {
        unset($this->storage[$key]);
        return true;
    }

    public function flush(): bool
    {
        $this->storage = [];
        return true;
    }
}
