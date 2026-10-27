<?php

declare(strict_types=1);

namespace Core\Notification\Channels;

use Core\Notification\NotificationService;

class DatabaseChannel implements NotificationChannelInterface
{
    public function __construct(protected NotificationService $notificationService)
    {
    }

    public function send(
        int|string $tenantId,
        mixed $recipient,
        string $title,
        string $message,
        array $options = []
    ): bool {
        $userId = is_int($recipient) ? $recipient : (isset($recipient->id) ? (int) $recipient->id : null);
        if ($userId === null) {
            return false;
        }

        $type = $options['type'] ?? 'info';
        $actionUrl = $options['action_url'] ?? null;
        $icon = $options['icon'] ?? null;

        $res = $this->notificationService->send(
            (int) $tenantId,
            $userId,
            $title,
            $message,
            $type,
            $actionUrl,
            $icon
        );

        return $res > 0;
    }
}
