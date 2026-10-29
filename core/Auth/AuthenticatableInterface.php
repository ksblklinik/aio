<?php

declare(strict_types=1);

namespace Core\Auth;

/**
 * Kontrak identitas autentikasi level 1 (Core).
 * Memungkinkan model pengguna domain (Level 2) bertindak sebagai aktor autentikasi
 * tanpa membuat Core bergantung ke atas pada kelas konkret domain.
 */
interface AuthenticatableInterface
{
    /**
     * Dapatkan identifier unik pengguna (ID numerik atau UUID).
     */
    public function getAuthIdentifier(): int|string|null;

    /**
     * Tentukan apakah pengguna memiliki hak akses Platform Superadmin global.
     */
    public function isSuperadmin(): bool;
}
