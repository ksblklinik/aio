<?php

declare(strict_types=1);

namespace Core\Storage;

interface StorageDriverInterface
{
    /**
     * Tulis konten ke path file
     */
    public function put(string $path, string $contents): bool;

    /**
     * Ambil konten file
     */
    public function get(string $path): ?string;

    /**
     * Periksa keberadaan file
     */
    public function exists(string $path): bool;

    /**
     * Hapus file
     */
    public function delete(string $path): bool;

    /**
     * Ambil ukuran file dalam bytes
     */
    public function size(string $path): int;

    /**
     * Ambil MIME type file
     */
    public function mimeType(string $path): ?string;

    /**
     * Ambil absolute path file pada filesystem
     */
    public function path(string $path): string;
}
