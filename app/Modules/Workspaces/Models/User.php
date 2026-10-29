<?php

declare(strict_types=1);

namespace App\Modules\Workspaces\Models;

use Core\Auth\AuthenticatableInterface;
use Core\Database\Connection;

class User implements AuthenticatableInterface
{
    public function __construct(
        public ?int $id = null,
        public string $name = '',
        public string $email = '',
        public string $passwordHash = '',
        public ?string $createdAt = null,
        public bool $isSuperadmin = false
    ) {
    }

    public function getAuthIdentifier(): int|string|null
    {
        return $this->id;
    }

    public function isSuperadmin(): bool
    {
        return $this->isSuperadmin;
    }

    public static function fromArray(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            name: (string) ($row['name'] ?? ''),
            email: (string) ($row['email'] ?? ''),
            passwordHash: (string) ($row['password_hash'] ?? ''),
            createdAt: isset($row['created_at']) ? (string) $row['created_at'] : null,
            isSuperadmin: !empty($row['is_superadmin'])
        );
    }

    public static function findById(Connection $db, int $id): ?self
    {
        $row = $db->selectOne("SELECT * FROM users WHERE id = ?", [$id]);
        return $row ? self::fromArray($row) : null;
    }

    public static function findByEmail(Connection $db, string $email): ?self
    {
        $row = $db->selectOne("SELECT * FROM users WHERE email = ?", [trim(strtolower($email))]);
        return $row ? self::fromArray($row) : null;
    }

    public static function create(Connection $db, array $data): self
    {
        $passwordHash = isset($data['password_hash'])
            ? (string) $data['password_hash']
            : password_hash((string) ($data['password'] ?? ''), PASSWORD_BCRYPT);

        $isSuperadmin = !empty($data['is_superadmin']) ? 1 : 0;

        $id = $db->insert('users', [
            'name' => trim((string) ($data['name'] ?? '')),
            'email' => trim(strtolower((string) ($data['email'] ?? ''))),
            'password_hash' => $passwordHash,
            'is_superadmin' => $isSuperadmin,
        ]);

        return new self(
            id: (int) $id,
            name: (string) $data['name'],
            email: trim(strtolower((string) $data['email'])),
            passwordHash: $passwordHash,
            isSuperadmin: $isSuperadmin === 1
        );
    }

    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->passwordHash);
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function getTenants(Connection $db): array
    {
        if (!$this->id) {
            return [];
        }

        return $db->selectAll(
            "SELECT t.*, tu.role 
             FROM tenants t 
             JOIN tenant_user tu ON tu.tenant_id = t.id 
             WHERE tu.user_id = ? 
             ORDER BY t.name ASC",
            [$this->id]
        );
    }

    public function getRoleInTenant(Connection $db, int $tenantId): ?string
    {
        if (!$this->id) {
            return null;
        }

        $row = $db->selectOne(
            "SELECT role FROM tenant_user WHERE tenant_id = ? AND user_id = ?",
            [$tenantId, $this->id]
        );

        return $row ? (string) $row['role'] : null;
    }

    public function hasRoleInTenant(Connection $db, int $tenantId, string|array $role): bool
    {
        $currentRole = $this->getRoleInTenant($db, $tenantId);
        if ($currentRole === null) {
            return false;
        }

        if (is_array($role)) {
            return in_array($currentRole, $role, true);
        }

        return $currentRole === $role;
    }

    public function isOwnerInTenant(Connection $db, int $tenantId): bool
    {
        return $this->hasRoleInTenant($db, $tenantId, 'owner');
    }
}
