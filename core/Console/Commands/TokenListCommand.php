<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Auth\ApiTokenService;
use Core\Console\Command;
use Core\Database\Connection;

class TokenListCommand extends Command
{
    protected string $signature = 'token:list';
    protected string $description = 'Menampilkan daftar seluruh API Token terdaftar';

    public function handle(array $arguments = [], array $options = []): int
    {
        /** @var Connection $db */
        $db = $this->container->get(Connection::class);

        $tenantId = isset($options['tenant']) ? (int) $options['tenant'] : null;

        if ($tenantId !== null) {
            $tokens = $db->selectAll(
                "SELECT t.id, t.name, t.abilities, t.last_used_at, t.expires_at, t.created_at,
                        u.name as user_name, w.name as tenant_name
                 FROM api_tokens t
                 JOIN users u ON u.id = t.user_id
                 JOIN tenants w ON w.id = t.tenant_id
                 WHERE t.tenant_id = ?
                 ORDER BY t.id DESC",
                [$tenantId]
            );
        } else {
            $tokens = $db->selectAll(
                "SELECT t.id, t.name, t.abilities, t.last_used_at, t.expires_at, t.created_at,
                        u.name as user_name, w.name as tenant_name
                 FROM api_tokens t
                 JOIN users u ON u.id = t.user_id
                 JOIN tenants w ON w.id = t.tenant_id
                 ORDER BY t.id DESC"
            );
        }

        if (empty($tokens)) {
            $this->warn("Belum ada API token yang terdaftar.");
            return 0;
        }

        $rows = [];
        foreach ($tokens as $t) {
            $isExpired = !empty($t['expires_at']) && strtotime($t['expires_at']) < time();
            $status = $isExpired ? "\033[31mKEDALUWARSA\033[0m" : "\033[32mAKTIF\033[0m";

            $rows[] = [
                'ID' => (string) $t['id'],
                'Nama' => (string) $t['name'],
                'Workspace' => (string) $t['tenant_name'],
                'Pemilik' => (string) $t['user_name'],
                'Abilities' => (string) $t['abilities'],
                'Status' => $status,
                'Terakhir Dipakai' => $t['last_used_at'] ?: 'Belum pernah',
            ];
        }

        $this->table(['ID', 'Nama', 'Workspace', 'Pemilik', 'Abilities', 'Status', 'Terakhir Dipakai'], $rows);
        $this->info("Total: " . count($rows) . " API token terdaftar.");

        return 0;
    }
}
