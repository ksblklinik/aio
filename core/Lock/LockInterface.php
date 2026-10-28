<?php

declare(strict_types=1);

namespace Core\Lock;

interface LockInterface
{
    /**
     * Coba mengakuisisi lock.
     *
     * @param int $waitTimeoutMs Waktu tunggu dalam milidetik jika lock sedang dipegang pihak lain (0 = non-blocking)
     * @return bool True jika berhasil diakuisisi, False jika gagal/timeout
     */
    public function acquire(int $waitTimeoutMs = 0): bool;

    /**
     * Lepas lock yang sedang dipegang oleh owner ini.
     *
     * @return bool
     */
    public function release(): bool;

    /**
     * Paksa melepas lock tanpa memandang owner.
     *
     * @return bool
     */
    public function forceRelease(): bool;

    /**
     * Cek apakah lock saat ini berhasil diakuisisi dan masih valid oleh proses ini.
     *
     * @return bool
     */
    public function isAcquired(): bool;

    /**
     * Dapatkan resource key lock.
     */
    public function getKey(): string;

    /**
     * Dapatkan owner identifier unik untuk sesi lock ini.
     */
    public function getOwner(): string;

    /**
     * Dapatkan durasi Time-To-Live (detik).
     */
    public function getTtl(): int;
}
