<?php

declare(strict_types=1);

namespace Core\Auth;

/**
 * Kontrak sesi autentikasi level 1 (Core).
 * Memungkinkan kernel platform (AuditLogger, AuthorizePermission, Inertia)
 * berinteraksi dengan modul auth tanpa kopling kelas konkret Level 2.
 */
interface AuthServiceInterface
{
    /**
     * Periksa apakah terdapat pengguna aktif yang sedang login dalam sesi.
     */
    public function check(): bool;

    /**
     * Dapatkan model pengguna aktif dalam sesi (atau null jika anonim).
     */
    public function user(): ?object;

    /**
     * Dapatkan workspace/tenant aktif dalam sesi (atau null jika belum memilih tenant).
     */
    public function currentTenant(): ?object;
}
