<?php

declare(strict_types=1);

namespace Core\Lock\Drivers;

class FileLockDriver implements LockDriverInterface
{
    /** @var array<string, resource> */
    private array $fileHandles = [];

    public function __construct(
        protected string $storagePath
    ) {
        if (!is_dir($this->storagePath)) {
            @mkdir($this->storagePath, 0755, true);
        }
    }

    public function acquire(string $key, string $owner, int $ttlSeconds, int $waitTimeoutMs = 0): bool
    {
        $filePath = $this->getFilePath($key);
        $startTime = microtime(true) * 1000;
        $now = (int)(microtime(true) * 1000);

        do {
            $handle = @fopen($filePath, 'c+');
            if ($handle !== false) {
                if (flock($handle, LOCK_EX | LOCK_NB)) {
                    // Cek apakah ada metadata lock lama yang masih valid
                    $content = stream_get_contents($handle);
                    $data = $content ? json_decode($content, true) : null;

                    if ($data && isset($data['expires_at']) && (int)$data['expires_at'] > $now && $data['owner'] !== $owner) {
                        // Masih valid dan dipegang pihak lain
                        flock($handle, LOCK_UN);
                        fclose($handle);
                    } else {
                        // Tulis metadata lock baru
                        ftruncate($handle, 0);
                        rewind($handle);
                        fwrite($handle, json_encode([
                            'key' => $key,
                            'owner' => $owner,
                            'expires_at' => $now + (max(1, $ttlSeconds) * 1000),
                            'created_at' => date('c'),
                        ]));
                        fflush($handle);

                        $this->fileHandles[$key] = $handle;
                        return true;
                    }
                } else {
                    fclose($handle);
                }
            }

            if ($waitTimeoutMs <= 0) {
                return false;
            }

            usleep(25000);
        } while ((microtime(true) * 1000 - $startTime) < $waitTimeoutMs);

        return false;
    }

    public function release(string $key, string $owner): bool
    {
        $filePath = $this->getFilePath($key);
        if (!file_exists($filePath)) {
            return false;
        }

        $content = @file_get_contents($filePath);
        $data = $content ? json_decode($content, true) : null;
        if ($data && isset($data['owner']) && $data['owner'] !== $owner) {
            return false; // Bukan pemilik sah
        }

        if (isset($this->fileHandles[$key])) {
            flock($this->fileHandles[$key], LOCK_UN);
            fclose($this->fileHandles[$key]);
            unset($this->fileHandles[$key]);
        }

        @unlink($filePath);
        return true;
    }

    public function forceRelease(string $key): bool
    {
        $filePath = $this->getFilePath($key);
        if (isset($this->fileHandles[$key])) {
            flock($this->fileHandles[$key], LOCK_UN);
            fclose($this->fileHandles[$key]);
            unset($this->fileHandles[$key]);
        }

        if (file_exists($filePath)) {
            return @unlink($filePath);
        }

        return true;
    }

    public function isLocked(string $key): bool
    {
        $filePath = $this->getFilePath($key);
        if (!file_exists($filePath)) {
            return false;
        }

        $content = @file_get_contents($filePath);
        $data = $content ? json_decode($content, true) : null;
        if (!$data || !isset($data['expires_at'])) {
            return false;
        }

        return (int)$data['expires_at'] > (int)(microtime(true) * 1000);
    }

    public function getOwner(string $key): ?string
    {
        $filePath = $this->getFilePath($key);
        if (!file_exists($filePath)) {
            return null;
        }

        $content = @file_get_contents($filePath);
        $data = $content ? json_decode($content, true) : null;
        if (!$data || !isset($data['expires_at']) || (int)$data['expires_at'] <= (int)(microtime(true) * 1000)) {
            return null;
        }

        return $data['owner'] ?? null;
    }

    public function pruneExpired(): int
    {
        $files = glob($this->storagePath . '/*.lock') ?: [];
        $count = 0;
        $now = (int)(microtime(true) * 1000);

        foreach ($files as $file) {
            $content = @file_get_contents($file);
            $data = $content ? json_decode($content, true) : null;
            if ($data && isset($data['expires_at']) && (int)$data['expires_at'] <= $now) {
                @unlink($file);
                $count++;
            }
        }

        return $count;
    }

    public function listActiveLocks(): array
    {
        $files = glob($this->storagePath . '/*.lock') ?: [];
        $now = (int)(microtime(true) * 1000);
        $results = [];

        foreach ($files as $file) {
            $content = @file_get_contents($file);
            $data = $content ? json_decode($content, true) : null;
            if ($data && isset($data['expires_at']) && (int)$data['expires_at'] > $now) {
                $results[] = [
                    'key' => (string)($data['key'] ?? basename($file, '.lock')),
                    'owner' => (string)($data['owner'] ?? 'unknown'),
                    'expires_at' => (int)$data['expires_at'],
                    'created_at' => (string)($data['created_at'] ?? date('c')),
                    'remaining_seconds' => max(0, (int) round(((int)$data['expires_at'] - $now) / 1000)),
                ];
            }
        }

        return $results;
    }

    protected function getFilePath(string $key): string
    {
        $hash = md5($key);
        return $this->storagePath . '/' . $hash . '.lock';
    }
}
