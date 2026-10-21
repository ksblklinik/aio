<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Database\Connection;
use Core\Notification\NotificationService;

class NotificationSendCommand extends Command
{
    protected string $signature = 'notification:send <user_id> <title> <message>';
    protected string $description = 'Kirim in-app notification baru ke pengguna di suatu tenant';

    public function handle(array $arguments = [], array $options = []): int
    {
        $userId = isset($arguments[0]) ? (int) $arguments[0] : 0;
        $title = $arguments[1] ?? '';
        $message = $arguments[2] ?? '';

        if ($userId <= 0 || empty($title) || empty($message)) {
            $this->error("Penggunaan: php aio notification:send <user_id> <title> <message> [--tenant=1] [--type=info]");
            return 1;
        }

        /** @var Connection $db */
        $db = $this->container->get(Connection::class);

        /** @var NotificationService $service */
        $service = $this->container->get(NotificationService::class);

        $tenantId = isset($options['tenant']) ? (int) $options['tenant'] : null;
        if ($tenantId === null) {
            $membership = $db->selectOne("SELECT tenant_id FROM tenant_user WHERE user_id = ? LIMIT 1", [$userId]);
            if (!$membership) {
                $this->error("Pengguna #{$userId} tidak terdaftar di workspace tenant mana pun.");
                return 1;
            }
            $tenantId = (int) $membership['tenant_id'];
        }

        $type = $options['type'] ?? 'info';
        $url = $options['url'] ?? null;
        $icon = $options['icon'] ?? 'bell';

        $id = $service->send($tenantId, $userId, $title, $message, $type, $url, $icon);

        $this->success("Notifikasi #{$id} berhasil dikirim ke Pengguna #{$userId} (Tenant #{$tenantId}).");
        return 0;
    }
}
