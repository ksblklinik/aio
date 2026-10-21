<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Database\Connection;

class WebhookListCommand extends Command
{
    protected string $signature = 'webhook:list';
    protected string $description = 'Menampilkan tabel daftar outbound webhooks terdaftar';

    public function handle(array $arguments = [], array $options = []): int
    {
        /** @var Connection $db */
        $db = $this->container->get(Connection::class);

        $tenantId = isset($options['tenant']) ? (int) $options['tenant'] : null;

        $sql = "SELECT w.id, w.name, w.url, w.events, w.is_active, w.created_at, t.name as tenant_name
                FROM webhooks w
                JOIN tenants t ON t.id = w.tenant_id";
        $params = [];

        if ($tenantId !== null) {
            $sql .= " WHERE w.tenant_id = ?";
            $params[] = $tenantId;
        }

        $sql .= " ORDER BY w.id DESC";

        $webhooks = $db->selectAll($sql, $params);

        if (empty($webhooks)) {
            $this->warn("Belum ada webhook yang terdaftar.");
            return 0;
        }

        $rows = [];
        foreach ($webhooks as $wh) {
            $status = $wh['is_active'] ? "\033[32mAKTIF\033[0m" : "\033[90mNONAKTIF\033[0m";

            $rows[] = [
                'ID' => (string) $wh['id'],
                'Nama' => (string) $wh['name'],
                'Workspace' => (string) $wh['tenant_name'],
                'URL Endpoint' => (string) $wh['url'],
                'Events' => (string) $wh['events'],
                'Status' => $status,
                'Dibuat' => (string) $wh['created_at'],
            ];
        }

        $this->table(['ID', 'Nama', 'Workspace', 'URL Endpoint', 'Events', 'Status', 'Dibuat'], $rows);
        $this->info("Total: " . count($rows) . " webhook ditampilkan.");

        return 0;
    }
}
