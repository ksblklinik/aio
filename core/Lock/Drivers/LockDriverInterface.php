<?php

declare(strict_types=1);

namespace Core\Lock\Drivers;

interface LockDriverInterface
{
    /**
     * Coba mengakuisisi lock untuk key dan owner tertentu.
     */
    public function acquire(string $key, string $owner, int $ttlSeconds, int $waitTimeoutMs = 0): bool;

    /**
     * Lepas lock hanya jika owner cocok.
     */
    public function release(string $key, string $owner): bool;

    /**
     * Paksa melepas lock tanpa memandang owner.
     */
    public function forceRelease(string $key): bool;

    /**
     * Periksa apakah key sedang terkunci oleh proses aktif.
     */
    public function isLocked(string $key): bool;

    /**
     * Ambil owner dari lock aktif untuk key tertentu.
     */
    public function getOwner(string $key): ?string;

    /**
     * Bersihkan seluruh lock yang sudah kedaluwarsa.
     */
    public function pruneExpired(): int;

    /**
     * Ambil seluruh lock yang sedang aktif.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listActiveLocks(): array;
}
