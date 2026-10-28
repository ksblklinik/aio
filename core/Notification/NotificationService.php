<?php

declare(strict_types=1);

namespace Core\Notification;

use Core\Database\Connection;

class NotificationService
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * Kirim notifikasi individual ke seorang pengguna dalam suatu workspace tenant.
     *
     * @param int $tenantId
     * @param int $userId
     * @param string $title
     * @param string $message
     * @param string $type Jenis notifikasi: 'info' | 'success' | 'warning' | 'danger'
     * @param string|null $actionUrl Tautan navigasi UI saat notifikasi diklik
     * @param string|null $icon Nama icon lucide (e.g. 'bell', 'check-circle', 'alert-triangle', 'key', 'folder')
     * @return int ID notifikasi yang baru dibuat
     */
    public function send(
        int $tenantId,
        int $userId,
        string $title,
        string $message,
        string $type = 'info',
        ?string $actionUrl = null,
        ?string $icon = null
    ): int {
        $allowedTypes = ['info', 'success', 'warning', 'danger'];
        if (!in_array($type, $allowedTypes, true)) {
            $type = 'info';
        }

        $id = $this->db->insert('notifications', [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'type' => $type,
            'title' => trim($title),
            'message' => trim($message),
            'action_url' => $actionUrl,
            'icon' => $icon,
            'read_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        try {
            if (function_exists('sse')) {
                sse('notifications', 'notification_received', [
                    'id' => (int) $id,
                    'user_id' => $userId,
                    'tenant_id' => $tenantId,
                    'type' => $type,
                    'title' => trim($title),
                    'message' => trim($message),
                    'action_url' => $actionUrl,
                    'icon' => $icon,
                    'created_at' => date('c'),
                ], (string) $tenantId);
            }
        } catch (\Throwable) {}

        return (int) $id;
    }

    /**
     * Kirim notifikasi siaran ke seluruh anggota terdaftar di dalam workspace tenant.
     *
     * @param int $tenantId
     * @param string $title
     * @param string $message
     * @param string $type
     * @param string|null $actionUrl
     * @param string|null $icon
     * @param int|null $excludeUserId Pengguna yang memicu event (dilewati agar tidak menotifikasi diri sendiri)
     * @return int Jumlah notifikasi yang berhasil dikirimkan
     */
    public function broadcastToTenant(
        int $tenantId,
        string $title,
        string $message,
        string $type = 'info',
        ?string $actionUrl = null,
        ?string $icon = null,
        ?int $excludeUserId = null
    ): int {
        $query = "SELECT user_id FROM tenant_user WHERE tenant_id = ?";
        $params = [$tenantId];

        if ($excludeUserId !== null) {
            $query .= " AND user_id != ?";
            $params[] = $excludeUserId;
        }

        $members = $this->db->selectAll($query, $params);
        $count = 0;

        foreach ($members as $member) {
            $userId = (int) $member['user_id'];
            $this->send($tenantId, $userId, $title, $message, $type, $actionUrl, $icon);
            $count++;
        }

        return $count;
    }

    /**
     * Ambil daftar notifikasi untuk pengguna tertentu di dalam workspace aktif.
     *
     * @param int $tenantId
     * @param int $userId
     * @param int $limit
     * @param bool $unreadOnly
     * @return array<array<string, mixed>>
     */
    public function getForUser(
        int $tenantId,
        int $userId,
        int $limit = 20,
        bool $unreadOnly = false
    ): array {
        $sql = "SELECT * FROM notifications WHERE tenant_id = ? AND user_id = ?";
        $params = [$tenantId, $userId];

        if ($unreadOnly) {
            $sql .= " AND read_at IS NULL";
        }

        $sql .= " ORDER BY created_at DESC, id DESC LIMIT ?";
        $params[] = $limit;

        $rows = $this->db->selectAll($sql, $params);

        return array_map(function (array $row) {
            return [
                'id' => (int) $row['id'],
                'tenant_id' => (int) $row['tenant_id'],
                'user_id' => (int) $row['user_id'],
                'type' => $row['type'],
                'title' => $row['title'],
                'message' => $row['message'],
                'action_url' => $row['action_url'],
                'icon' => $row['icon'],
                'read_at' => $row['read_at'],
                'created_at' => $row['created_at'],
                'is_read' => $row['read_at'] !== null,
            ];
        }, $rows);
    }

    /**
     * Hitung jumlah notifikasi yang belum dibaca pengguna dalam workspace aktif.
     *
     * @param int $tenantId
     * @param int $userId
     * @return int
     */
    public function getUnreadCount(int $tenantId, int $userId): int
    {
        $sql = "SELECT COUNT(*) as count FROM notifications WHERE tenant_id = ? AND user_id = ? AND read_at IS NULL";
        $row = $this->db->selectOne($sql, [$tenantId, $userId]);

        return $row ? (int) $row['count'] : 0;
    }

    /**
     * Tandai sebuah notifikasi telah dibaca.
     *
     * @param int $tenantId
     * @param int $userId
     * @param int $notificationId
     * @return bool
     */
    public function markAsRead(int $tenantId, int $userId, int $notificationId): bool
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->getPdo()->prepare(
            "UPDATE notifications SET read_at = ? WHERE id = ? AND tenant_id = ? AND user_id = ? AND read_at IS NULL"
        );
        $stmt->execute([$now, $notificationId, $tenantId, $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Tandai seluruh notifikasi yang belum dibaca milik pengguna menjadi telah dibaca.
     *
     * @param int $tenantId
     * @param int $userId
     * @return int Jumlah baris notifikasi yang diperbarui
     */
    public function markAllAsRead(int $tenantId, int $userId): int
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->getPdo()->prepare(
            "UPDATE notifications SET read_at = ? WHERE tenant_id = ? AND user_id = ? AND read_at IS NULL"
        );
        $stmt->execute([$now, $tenantId, $userId]);

        return $stmt->rowCount();
    }

    /**
     * Hapus sebuah notifikasi milik pengguna.
     *
     * @param int $tenantId
     * @param int $userId
     * @param int $notificationId
     * @return bool
     */
    public function delete(int $tenantId, int $userId, int $notificationId): bool
    {
        $stmt = $this->db->getPdo()->prepare(
            "DELETE FROM notifications WHERE id = ? AND tenant_id = ? AND user_id = ?"
        );
        $stmt->execute([$notificationId, $tenantId, $userId]);

        return $stmt->rowCount() > 0;
    }
}
