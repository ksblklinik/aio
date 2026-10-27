<?php

declare(strict_types=1);

namespace Core\Notification\Channels;

interface NotificationChannelInterface
{
    /**
     * Kirim notifikasi melalui channel ini.
     *
     * @param int|string $tenantId
     * @param mixed $recipient Bisa berupa ID user (int), email (string), no hp (string), atau objek User/Member
     * @param string $title
     * @param string $message
     * @param array $options Opsi tambahan (action_url, icon, phone, chat_id, dll)
     * @return bool True jika berhasil dikirim, false jika gagal
     */
    public function send(
        int|string $tenantId,
        mixed $recipient,
        string $title,
        string $message,
        array $options = []
    ): bool;
}
