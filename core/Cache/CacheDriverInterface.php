<?php

declare(strict_types=1);

namespace Core\Cache;

interface CacheDriverInterface
{
    /**
     * Dapatkan item dari cache berdasarkan key.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Simpan item ke dalam cache dengan batas waktu TTL (dalam detik).
     * Jika $ttl null atau 0, cache disimpan tanpa batas waktu (atau waktu default).
     */
    public function put(string $key, mixed $value, ?int $ttl = null): bool;

    /**
     * Periksa apakah key tertentu ada dan masih valid di cache.
     */
    public function has(string $key): bool;

    /**
     * Hapus item dari cache.
     */
    public function forget(string $key): bool;

    /**
     * Bersihkan seluruh item pada cache driver ini.
     */
    public function flush(): bool;
}
