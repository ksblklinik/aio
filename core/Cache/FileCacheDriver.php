<?php

declare(strict_types=1);

namespace Core\Cache;

class FileCacheDriver implements CacheDriverInterface
{
    protected string $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/\\');
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0755, true);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $filePath = $this->getFilePath($key);
        if (!file_exists($filePath)) {
            return $default;
        }

        $raw = @file_get_contents($filePath);
        if ($raw === false) {
            return $default;
        }

        $payload = @unserialize($raw);
        if (!is_array($payload) || !array_key_exists('data', $payload)) {
            $this->forget($key);
            return $default;
        }

        if ($payload['expires_at'] !== null && $payload['expires_at'] <= time()) {
            $this->forget($key);
            return $default;
        }

        return $payload['data'];
    }

    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        $filePath = $this->getFilePath($key);
        $expiresAt = ($ttl !== null) ? (time() + $ttl) : null;

        $payload = [
            'expires_at' => $expiresAt,
            'data' => $value,
        ];

        $serialized = serialize($payload);
        $tempPath = $filePath . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($tempPath, $serialized, LOCK_EX) !== false) {
            return @rename($tempPath, $filePath);
        }

        return false;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function forget(string $key): bool
    {
        $filePath = $this->getFilePath($key);
        if (file_exists($filePath)) {
            return @unlink($filePath);
        }
        return true;
    }

    public function flush(): bool
    {
        if (!is_dir($this->directory)) {
            return true;
        }

        $files = glob($this->directory . DIRECTORY_SEPARATOR . '*.cache');
        if ($files !== false) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }

        return true;
    }

    protected function getFilePath(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.cache';
    }
}
