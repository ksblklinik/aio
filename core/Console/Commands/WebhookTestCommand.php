<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Database\Connection;
use Core\Webhook\WebhookDispatcher;

class WebhookTestCommand extends Command
{
    protected string $signature = 'webhook:test <webhook_id>';
    protected string $description = 'Kirim payload tes ping ke webhook tertentu untuk menguji konektivitas';

    public function handle(array $arguments = [], array $options = []): int
    {
        $webhookId = isset($arguments[0]) ? (int) $arguments[0] : 0;
        if ($webhookId <= 0) {
            $this->error("Penggunaan: php aio webhook:test <webhook_id> [--event=ping]");
            return 1;
        }

        /** @var Connection $db */
        $db = $this->container->get(Connection::class);

        /** @var WebhookDispatcher $dispatcher */
        $dispatcher = $this->container->get(WebhookDispatcher::class);

        $wh = $db->selectOne("SELECT * FROM webhooks WHERE id = ?", [$webhookId]);
        if (!$wh) {
            $this->error("Webhook #{$webhookId} tidak ditemukan.");
            return 1;
        }

        $event = (string) ($options['event'] ?? 'ping');
        $tenantId = (int) $wh['tenant_id'];

        $count = $dispatcher->dispatch($tenantId, $event, [
            'test' => true,
            'message' => 'Tes koneksi webhook dari aio Framework CLI',
            'sent_at' => date('Y-m-d H:i:s'),
        ]);

        $this->success("Pekerjaan pengiriman webhook #{$webhookId} ({$wh['name']}) berhasil dimasukkan ke antrean 'webhooks'.");
        $this->info("Jalankan 'php aio queue:work --queue=webhooks --once' untuk mengeksekusi pengiriman sekarang.");

        return 0;
    }
}
