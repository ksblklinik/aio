<?php

declare(strict_types=1);

namespace Core\Auth;

use Core\Database\Connection;

class Gate
{
    private Connection $db;
    /** @var array<string, bool> */
    private array $cache = [];

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }

    /**
     * Memeriksa apakah user memiliki permission tertentu dalam konteks tenant
     */
    public function allows(object|int|null $user, string $permission, ?int $tenantId = null): bool
    {
        if ($user === null) {
            return false;
        }

        $userId = 0;
        $isSuperadmin = false;

        if ($user instanceof AuthenticatableInterface) {
            $userId = (int) ($user->getAuthIdentifier() ?? 0);
            $isSuperadmin = $user->isSuperadmin();
        } elseif (is_object($user)) {
            $userId = (int) ($user->id ?? 0);
            $isSuperadmin = !empty($user->isSuperadmin);
        } else {
            $userId = (int) $user;
            $userRow = $this->db->selectOne("SELECT is_superadmin FROM users WHERE id = ?", [$userId]);
            $isSuperadmin = !empty($userRow['is_superadmin']);
        }

        if ($isSuperadmin) {
            return true;
        }

        if ($tenantId === null) {
            return false;
        }

        // Cache key per request
        $cacheKey = "{$userId}:{$tenantId}:{$permission}";
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        // 2. Ambil peran user di tenant tersebut
        $membership = $this->db->selectOne(
            "SELECT role FROM tenant_user WHERE tenant_id = ? AND user_id = ?",
            [$tenantId, $userId]
        );

        if (!$membership || empty($membership['role'])) {
            return $this->cache[$cacheKey] = false;
        }

        $roleSlug = (string) $membership['role'];

        // Owner workspace selalu memiliki akses tak terbatas (*)
        if ($roleSlug === 'owner') {
            return $this->cache[$cacheKey] = true;
        }

        // 3. Cek izin di tabel role_permissions berdasarkan role_id atau role slug
        $roleRow = $this->db->selectOne(
            "SELECT id FROM roles WHERE tenant_id = ? AND slug = ?",
            [$tenantId, $roleSlug]
        );

        if ($roleRow) {
            $roleId = (int) $roleRow['id'];
            $hasPerm = $this->db->selectOne(
                "SELECT id FROM role_permissions WHERE tenant_id = ? AND role_id = ? AND (permission_key = ? OR permission_key = '*')",
                [$tenantId, $roleId, $permission]
            );

            if ($hasPerm !== null) {
                return $this->cache[$cacheKey] = true;
            }
        }

        // 4. Fallback default untuk admin jika belum ada penyesuaian khusus di role_permissions
        if ($roleSlug === 'admin') {
            return $this->cache[$cacheKey] = true;
        }

        return $this->cache[$cacheKey] = false;
    }

    public function denies(object|int|null $user, string $permission, ?int $tenantId = null): bool
    {
        return !$this->allows($user, $permission, $tenantId);
    }

    public function authorize(object|int|null $user, string $permission, ?int $tenantId = null): void
    {
        if ($this->denies($user, $permission, $tenantId)) {
            throw new AuthorizationException("Aksi tidak diizinkan: izin '{$permission}' diperlukan.");
        }
    }

    /**
     * Dapatkan seluruh daftar izin yang dimiliki user di tenant tertentu
     *
     * @return array<string>
     */
    public function getPermissionsForUser(object|int|null $user, int $tenantId): array
    {
        if ($user === null) {
            return [];
        }

        $userId = 0;
        $isSuperadmin = false;

        if ($user instanceof AuthenticatableInterface) {
            $userId = (int) ($user->getAuthIdentifier() ?? 0);
            $isSuperadmin = $user->isSuperadmin();
        } elseif (is_object($user)) {
            $userId = (int) ($user->id ?? 0);
            $isSuperadmin = !empty($user->isSuperadmin);
        } else {
            $userId = (int) $user;
            $userRow = $this->db->selectOne("SELECT is_superadmin FROM users WHERE id = ?", [$userId]);
            $isSuperadmin = !empty($userRow['is_superadmin']);
        }

        if ($isSuperadmin) {
            return ['*'];
        }

        $membership = $this->db->selectOne(
            "SELECT role FROM tenant_user WHERE tenant_id = ? AND user_id = ?",
            [$tenantId, $userId]
        );

        if (!$membership || empty($membership['role'])) {
            return [];
        }

        $roleSlug = (string) $membership['role'];
        if ($roleSlug === 'owner') {
            return ['*'];
        }

        $roleRow = $this->db->selectOne(
            "SELECT id FROM roles WHERE tenant_id = ? AND slug = ?",
            [$tenantId, $roleSlug]
        );

        if (!$roleRow) {
            return $roleSlug === 'admin' ? ['*'] : [];
        }

        $perms = $this->db->select(
            "SELECT permission_key FROM role_permissions WHERE tenant_id = ? AND role_id = ?",
            [$tenantId, (int) $roleRow['id']]
        );

        $keys = array_column($perms, 'permission_key');
        if (empty($keys) && $roleSlug === 'admin') {
            return ['*'];
        }

        return $keys;
    }
}
