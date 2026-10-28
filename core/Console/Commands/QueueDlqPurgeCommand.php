<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Queue\DeadLetterQueueInterface;
use Throwable;

class QueueDlqPurgeCommand extends Command
{
    protected string $signature = 'queue:dlq:purge';
    protected string $description = 'Bersihkan arsip pekerjaan gagal lama dari Dead-Letter Queue';

    public function handle(array $arguments = [], array $options = []): int
    {
        /** @var DeadLetterQueueInterface $dlq */
        $dlq = $this->container->get(DeadLetterQueueInterface::class);

        $days = isset($options['days']) ? (int) $options['days'] : null;
        $queue = isset($options['queue']) ? (string) $options['queue'] : null;

        try {
            $purged = $dlq->purge($queue, $days);
            $msg = $days !== null
                ? sprintf("Berhasil menghapus %d pekerjaan gagal yang berusia lebih dari %d hari.", $purged, $days)
                : sprintf("Berhasil menghapus seluruh %d pekerjaan gagal di Dead-Letter Queue.", $purged);

            $this->success($msg);
            return 0;
        } catch (Throwable $e) {
            $this->error("Gagal membersihkan Dead-Letter Queue: " . $e->getMessage());
            return 1;
        }
    }
}
