<?php

declare(strict_types=1);

namespace Core\Storage;

use InvalidArgumentException;
use RuntimeException;

class LocalStorageDriver implements StorageDriverInterface
{
    private string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
        if (!is_dir($this->root)) {
            @mkdir($this->root, 0755, true);
        }
    }

    public function put(string $path, string $contents): bool
    {
        $fullPath = $this->path($path);
        $directory = dirname($fullPath);

        if (!is_dir($directory)) {
            if (!@mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException("Gagal membuat direktori storage: {$directory}");
            }
        }

        return file_put_contents($fullPath, $contents) !== false;
    }

    public function get(string $path): ?string
    {
        $fullPath = $this->path($path);
        if (!file_exists($fullPath) || !is_file($fullPath)) {
            return null;
        }

        $content = file_get_contents($fullPath);
        return $content !== false ? $content : null;
    }

    public function exists(string $path): bool
    {
        $fullPath = $this->path($path);
        return file_exists($fullPath) && is_file($fullPath);
    }

    public function delete(string $path): bool
    {
        $fullPath = $this->path($path);
        if (file_exists($fullPath) && is_file($fullPath)) {
            return @unlink($fullPath);
        }
        return false;
    }

    public function size(string $path): int
    {
        $fullPath = $this->path($path);
        if (!file_exists($fullPath) || !is_file($fullPath)) {
            return 0;
        }

        $size = filesize($fullPath);
        return $size !== false ? $size : 0;
    }

    public function mimeType(string $path): ?string
    {
        $fullPath = $this->path($path);
        if (!file_exists($fullPath) || !is_file($fullPath)) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return 'application/octet-stream';
        }

        $mime = finfo_file($finfo, $fullPath);
        finfo_close($finfo);

        return $mime !== false ? $mime : 'application/octet-stream';
    }

    public function path(string $path): string
    {
        $cleanPath = ltrim(str_replace(['\\', "\0"], ['/', ''], $path), '/');

        // Proteksi Directory Traversal: path tidak boleh mengandung '..'
        $parts = explode('/', $cleanPath);
        $safeParts = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new InvalidArgumentException("Akses direktori traversal tidak diizinkan: {$path}");
            }
            $safeParts[] = $part;
        }

        return $this->root . '/' . implode('/', $safeParts);
    }
}
