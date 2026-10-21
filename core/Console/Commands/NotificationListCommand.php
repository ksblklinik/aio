<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Database\Connection;

class NotificationListCommand extends Command
{
    protected string $signature = 'notification:list';
    protected string $description = 'Menampilkan tabel daftar in-app notifications';

    public function handle(array $arguments = [], array $options = []): int
    {
        /** @var Connection $db */
        $db = $this->container->get(Connection::class);

        $limit = isset($options['limit']) ? (int) $options['limit'] : 20;
        $tenantId = isset($options['tenant']) ? (int) $options['tenant'] : null;
        $userId = isset($options['user']) ? (int) $options['user'] : null;

        $sql = "SELECT n.id, n.type, n.title, n.message, n.read_at, n.created_at,
                       u.name as user_name, t.name as tenant_name
                FROM notifications n
                JOIN users u ON u.id = n.user_id
                JOIN tenants t ON t.id = n.tenant_id";

        $conditions = [];
        $params = [];

        if ($tenantId !== null) {
            $conditions[] = "n.tenant_id = ?";
            $params[] = $tenantId;
        }

        if ($userId !== null) {
            $conditions[] = "n.user_id = ?";
            $params[] = $userId;
        }

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        $sql .= " ORDER BY n.id DESC LIMIT ?";
        $params[] = $limit;

        $notifications = $db->selectAll($sql, $params);

        if (empty($notifications)) {
            $this->warn("Belum ada notifikasi yang tercatat.");
            return 0;
        }

        $rows = [];
        foreach ($notifications as $n) {
            $isRead = !empty($n['read_at']);
            $status = $isRead ? "\033[32mDIBACA\033[0m" : "\033[33mBELUM DIBACA\033[0m";

            $rows[] = [
                'ID' => (string) $n['id'],
                'Tipe' => strtoupper((string) $n['type']),
                'Judul' => (string) $n['title'],
                'Penerima' => (string) $n['user_name'],
                'Workspace' => (string) $n['tenant_name'],
                'Status' => $status,
                'Waktu' => (string) $n['created_at'],
            ];
        }

        $this->table(['ID', 'Tipe', 'Judul', 'Penerima', 'Workspace', 'Status', 'Waktu'], $rows);
        $this->info("Total: " . count($rows) . " notifikasi ditampilkan.");

        return 0;
    }
}
