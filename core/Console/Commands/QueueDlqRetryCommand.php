<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Queue\DeadLetterQueueInterface;
use Throwable;

class QueueDlqRetryCommand extends Command
{
    protected string $signature = 'queue:dlq:retry';
    protected string $description = 'Replay pekerjaan gagal dari Dead-Letter Queue kembali ke antrean aktif';

    public function handle(array $arguments = [], array $options = []): int
    {
        /** @var DeadLetterQueueInterface $dlq */
        $dlq = $this->container->get(DeadLetterQueueInterface::class);

        $retryAll = isset($options['all']);
        $queue = isset($options['queue']) ? (string) $options['queue'] : null;
        $id = isset($arguments[0]) ? (int) $arguments[0] : null;

        if (!$retryAll && $id === null) {
            $this->error("Harap tentukan ID pekerjaan atau gunakan opsi --all: php aio queue:dlq:retry <id> atau php aio queue:dlq:retry --all");
            return 1;
        }

        try {
            if ($retryAll) {
                $count = $dlq->retryAll($queue);
                $this->success(sprintf("Berhasil me-replay %d pekerjaan dari Dead-Letter Queue kembali ke antrean aktif.", $count));
                return 0;
            }

            $success = $dlq->retry($id);
            if ($success) {
                $this->success("Pekerjaan gagal #{$id} berhasil dimasukkan kembali ke antrean aktif.");
                return 0;
            }

            $this->warn("Pekerjaan gagal #{$id} tidak ditemukan di Dead-Letter Queue.");
            return 1;
        } catch (Throwable $e) {
            $this->error("Gagal me-replay pekerjaan: " . $e->getMessage());
            return 1;
        }
    }
}
