<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Database\Connection;

class TenantListCommand extends Command
{
    protected string $signature = 'tenant:list';
    protected string $description = 'Menampilkan tabel seluruh workspace tenant terdaftar dan jumlah anggotanya';

    public function handle(array $arguments = [], array $options = []): int
    {
        /** @var Connection $db */
        $db = $this->container->get(Connection::class);

        $tenants = $db->selectAll(
            "SELECT t.id, t.name, t.slug, t.plan, t.created_at, COUNT(tu.id) as total_users
             FROM tenants t
             LEFT JOIN tenant_user tu ON tu.tenant_id = t.id
             GROUP BY t.id
             ORDER BY t.id ASC"
        );

        if (empty($tenants)) {
            $this->warn("Belum ada tenant/workspace yang terdaftar dalam database.");
            return 0;
        }

        $rows = [];
        foreach ($tenants as $t) {
            $rows[] = [
                'ID' => $t['id'],
                'Nama Workspace' => $t['name'],
                'Slug' => $t['slug'],
                'Paket Plan' => strtoupper((string) $t['plan']),
                'Anggota' => $t['total_users'] . ' orang',
                'Dibuat' => $t['created_at'],
            ];
        }

        $this->table(['ID', 'Nama Workspace', 'Slug', 'Paket Plan', 'Anggota', 'Dibuat'], $rows);
        $this->info("Total: " . count($tenants) . " workspace terdaftar.");
        return 0;
    }
}
