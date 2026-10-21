<?php

declare(strict_types=1);

namespace Core\Auth;

use Core\Database\Connection;

class ApiTokenService
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * Buat Personal Access Token (PAT) baru untuk suatu workspace tenant.
     *
     * @param int $tenantId
     * @param int $userId
     * @param string $name Contoh: "Zapier Integration", "Mobile Client"
     * @param array<string> $abilities Contoh: ['*'] atau ['projects:read', 'projects:write']
     * @param string|null $expiresAt Format: 'Y-m-d H:i:s' atau null untuk token tanpa masa kedaluwarsa
     * @return array{id: int, name: string, plain_token: string, abilities: array<string>, expires_at: ?string}
     */
    public function createToken(
        int $tenantId,
        int $userId,
        string $name,
        array $abilities = ['*'],
        ?string $expiresAt = null
    ): array {
        // Format token: aio_pat_ + 48 hex characters
        $plainToken = 'aio_pat_' . bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $plainToken);

        $jsonAbilities = json_encode($abilities, JSON_UNESCAPED_SLASHES);

        $id = $this->db->insert('api_tokens', [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'name' => trim($name),
            'token_hash' => $tokenHash,
            'abilities' => $jsonAbilities,
            'expires_at' => $expiresAt,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'id' => (int) $id,
            'name' => $name,
            'plain_token' => $plainToken,
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Validasi plaintext token yang dikirimkan pada header HTTP Bearer.
     *
     * @param string $plainToken
     * @return array<string, mixed>|null Mengembalikan data token, user, dan tenant jika valid, atau null jika tidak valid/kedaluwarsa.
     */
    public function validateToken(string $plainToken): ?array
    {
        $plainToken = trim($plainToken);
        if (str_starts_with($plainToken, 'Bearer ')) {
            $plainToken = substr($plainToken, 7);
        }

        if ($plainToken === '') {
            return null;
        }

        $tokenHash = hash('sha256', $plainToken);

        $row = $this->db->selectOne(
            "SELECT t.*, u.name as user_name, u.email as user_email, u.is_superadmin,
                    w.name as tenant_name, w.slug as tenant_slug, w.plan as tenant_plan,
                    tu.role as user_role
             FROM api_tokens t
             JOIN users u ON u.id = t.user_id
             JOIN tenants w ON w.id = t.tenant_id
             LEFT JOIN tenant_user tu ON tu.tenant_id = t.tenant_id AND tu.user_id = t.user_id
             WHERE t.token_hash = ?",
            [$tokenHash]
        );

        if (!$row) {
            return null;
        }

        // Periksa apakah token telah kedaluwarsa
        if (!empty($row['expires_at'])) {
            if (strtotime($row['expires_at']) < time()) {
                return null;
            }
        }

        // Perbarui last_used_at
        $now = date('Y-m-d H:i:s');
        $this->db->statement(
            "UPDATE api_tokens SET last_used_at = ? WHERE id = ?",
            [$now, $row['id']]
        );
        $row['last_used_at'] = $now;

        $abilities = json_decode($row['abilities'] ?? '["*"]', true) ?: ['*'];
        $row['abilities_list'] = $abilities;

        return $row;
    }

    /**
     * Periksa apakah token memiliki hak akses (ability) tertentu.
     *
     * @param array<string, mixed> $tokenRecord
     * @param string $ability
     * @return bool
     */
    public function hasAbility(array $tokenRecord, string $ability): bool
    {
        $abilities = $tokenRecord['abilities_list'] ?? json_decode($tokenRecord['abilities'] ?? '["*"]', true) ?: ['*'];

        if (in_array('*', $abilities, true)) {
            return true;
        }

        return in_array($ability, $abilities, true);
    }

    /**
     * Cabut / hapus API token berdasarkan ID.
     */
    public function revokeToken(int $tokenId, ?int $tenantId = null): bool
    {
        if ($tenantId !== null) {
            $count = $this->db->statement(
                "DELETE FROM api_tokens WHERE id = ? AND tenant_id = ?",
                [$tokenId, $tenantId]
            );
        } else {
            $count = $this->db->statement(
                "DELETE FROM api_tokens WHERE id = ?",
                [$tokenId]
            );
        }

        return $count > 0;
    }

    /**
     * Ambil daftar seluruh token aktif untuk suatu tenant workspace.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTokensForTenant(int $tenantId): array
    {
        $rows = $this->db->selectAll(
            "SELECT t.id, t.name, t.abilities, t.last_used_at, t.expires_at, t.created_at, u.name as user_name
             FROM api_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.tenant_id = ?
             ORDER BY t.id DESC",
            [$tenantId]
        );

        return array_map(function ($row) {
            $row['abilities_list'] = json_decode($row['abilities'] ?? '["*"]', true) ?: ['*'];
            $row['is_expired'] = !empty($row['expires_at']) && strtotime($row['expires_at']) < time();
            return $row;
        }, $rows);
    }
}
