<?php

declare(strict_types=1);

namespace Core\Lock;

interface LockManagerInterface
{
    /**
     * Buat instance LockInterface untuk resource tertentu.
     *
     * @param string $resourceKey
     * @param int $ttlSeconds Durasi Time-To-Live (detik) sebelum auto-release (default: 10)
     * @param string|null $owner Token unik owner (jika null, di-generate secara acak)
     * @return LockInterface
     */
    public function lock(string $resourceKey, int $ttlSeconds = 10, ?string $owner = null): LockInterface;

    /**
     * Coba mengakuisisi lock secara langsung, mengembalikan instance Lock jika berhasil atau null jika gagal.
     *
     * @param string $resourceKey
     * @param int $ttlSeconds
     * @param int $waitTimeoutMs
     * @param string|null $owner
     * @return LockInterface|null
     */
    public function acquire(string $resourceKey, int $ttlSeconds = 10, int $waitTimeoutMs = 0, ?string $owner = null): ?LockInterface;

    /**
     * Jalankan callback di dalam proteksi atomic lock otomatis.
     * Lock akan otomatis diakuisisi sebelum callback dijalankan, dan otomatis dilepas setelah callback selesai (termasuk jika melempar exception).
     *
     * @template T
     * @param string $resourceKey
     * @param callable(): T $callback
     * @param int $ttlSeconds
     * @param int $waitTimeoutMs
     * @param string|null $owner
     * @return T
     * @throws LockTimeoutException Jika gagal mengakuisisi lock dalam batas waktu
     */
    public function run(string $resourceKey, callable $callback, int $ttlSeconds = 10, int $waitTimeoutMs = 3000, ?string $owner = null): mixed;

    /**
     * Lepaskan lock untuk resource tertentu berdasarkan owner.
     */
    public function release(string $resourceKey, ?string $owner = null): bool;

    /**
     * Cek apakah resource tertentu sedang terkunci oleh proses manapun.
     */
    public function isLocked(string $resourceKey): bool;

    /**
     * Bersihkan seluruh lock yang telah kedaluwarsa.
     *
     * @return int Jumlah lock usang yang dibersihkan
     */
    public function pruneExpired(): int;

    /**
     * Dapatkan daftar seluruh lock aktif (untuk monitoring & diagnostik).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listActiveLocks(): array;
}
