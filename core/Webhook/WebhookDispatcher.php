<?php

declare(strict_types=1);

namespace Core\Webhook;

use Core\Database\Connection;
use Core\Queue\QueueManager;
use Core\Webhook\Jobs\SendWebhookJob;

class WebhookDispatcher
{
    public function __construct(
        private Connection $db,
        private QueueManager $queue
    ) {
    }

    /**
     * Daftarkan webhook baru untuk suatu workspace tenant.
     *
     * @param int $tenantId
     * @param string $name
     * @param string $url
     * @param array<string> $events
     * @param string|null $secret
     * @return array<string, mixed>
     */
    public function register(
        int $tenantId,
        string $name,
        string $url,
        array $events = ['*'],
        ?string $secret = null
    ): array {
        if ($secret === null || trim($secret) === '') {
            $secret = 'whsec_' . bin2hex(random_bytes(24));
        }

        $jsonEvents = json_encode($events, JSON_UNESCAPED_SLASHES);

        $id = $this->db->insert('webhooks', [
            'tenant_id' => $tenantId,
            'name' => trim($name),
            'url' => trim($url),
            'secret' => $secret,
            'events' => $jsonEvents,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'id' => (int) $id,
            'tenant_id' => $tenantId,
            'name' => $name,
            'url' => $url,
            'secret' => $secret,
            'events' => $events,
            'is_active' => 1,
        ];
    }

    /**
     * Kirim event ke seluruh webhook tenant yang berlangganan event tersebut.
     * Pengiriman dimasukkan ke antrean (Queue) secara asinkronus agar tidak memblokir HTTP.
     *
     * @param int $tenantId
     * @param string $event Contoh: 'project.created', 'task.completed'
     * @param array<string, mixed> $payload
     * @return int Jumlah webhook yang berhasil di-enqueue
     */
    public function dispatch(int $tenantId, string $event, array $payload): int
    {
        $webhooks = $this->db->selectAll(
            "SELECT * FROM webhooks WHERE tenant_id = ? AND is_active = 1",
            [$tenantId]
        );

        $dispatchedCount = 0;

        foreach ($webhooks as $wh) {
            $subscribedEvents = json_decode($wh['events'] ?? '["*"]', true) ?: ['*'];

            $isSubscribed = in_array('*', $subscribedEvents, true) || in_array($event, $subscribedEvents, true);
            if (!$isSubscribed) {
                continue;
            }

            $jobData = [
                'webhook_id' => (int) $wh['id'],
                'tenant_id' => $tenantId,
                'url' => $wh['url'],
                'secret' => $wh['secret'],
                'event' => $event,
                'payload' => $payload,
            ];

            $this->queue->push(
                jobClass: SendWebhookJob::class,
                data: $jobData,
                tenantId: $tenantId,
                queue: 'webhooks',
                delaySeconds: 0,
                maxTries: 3
            );

            $dispatchedCount++;
        }

        return $dispatchedCount;
    }

    /**
     * Hitung tanda tangan HMAC-SHA256 untuk memverifikasi keaslian payload.
     */
    public function calculateSignature(string $payloadJson, int $timestamp, string $secret): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$payloadJson}", $secret);
    }

    /**
     * Ambil seluruh webhook untuk suatu tenant.
     *
     * @return array<array<string, mixed>>
     */
    public function getForTenant(int $tenantId): array
    {
        $rows = $this->db->selectAll(
            "SELECT * FROM webhooks WHERE tenant_id = ? ORDER BY id DESC",
            [$tenantId]
        );

        return array_map(function (array $r) {
            $r['events_list'] = json_decode($r['events'] ?? '["*"]', true) ?: ['*'];
            return $r;
        }, $rows);
    }

    public function find(int $tenantId, int $webhookId): ?array
    {
        $row = $this->db->selectOne(
            "SELECT * FROM webhooks WHERE id = ? AND tenant_id = ?",
            [$webhookId, $tenantId]
        );

        if ($row) {
            $row['events_list'] = json_decode($row['events'] ?? '["*"]', true) ?: ['*'];
        }

        return $row;
    }

    public function toggle(int $tenantId, int $webhookId): bool
    {
        $wh = $this->find($tenantId, $webhookId);
        if (!$wh) {
            return false;
        }

        $newActive = $wh['is_active'] ? 0 : 1;
        $this->db->statement(
            "UPDATE webhooks SET is_active = ? WHERE id = ? AND tenant_id = ?",
            [$newActive, $webhookId, $tenantId]
        );

        return true;
    }

    public function delete(int $tenantId, int $webhookId): bool
    {
        $stmt = $this->db->getPdo()->prepare("DELETE FROM webhooks WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$webhookId, $tenantId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Ambil riwayat pengiriman (deliveries) untuk webhook tertentu atau seluruh tenant.
     *
     * @return array<array<string, mixed>>
     */
    public function getDeliveries(int $tenantId, ?int $webhookId = null, int $limit = 30): array
    {
        if ($webhookId !== null) {
            return $this->db->selectAll(
                "SELECT d.*, w.name as webhook_name, w.url as webhook_url
                 FROM webhook_deliveries d
                 JOIN webhooks w ON w.id = d.webhook_id
                 WHERE d.tenant_id = ? AND d.webhook_id = ?
                 ORDER BY d.id DESC LIMIT ?",
                [$tenantId, $webhookId, $limit]
            );
        }

        return $this->db->selectAll(
            "SELECT d.*, w.name as webhook_name, w.url as webhook_url
             FROM webhook_deliveries d
             JOIN webhooks w ON w.id = d.webhook_id
             WHERE d.tenant_id = ?
             ORDER BY d.id DESC LIMIT ?",
            [$tenantId, $limit]
        );
    }
}
