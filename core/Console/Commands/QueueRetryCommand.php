<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Queue\QueueManager;

class QueueRetryCommand extends Command
{
    protected string $signature = 'queue:retry <id>';
    protected string $description = 'Mencoba kembali pekerjaan yang gagal dari failed_jobs';

    public function handle(array $arguments = [], array $options = []): int
    {
        $id = isset($arguments[0]) ? (int) $arguments[0] : 0;
        if ($id <= 0) {
            $this->error("Penggunaan: php aio queue:retry <failed_job_id>");
            return 1;
        }

        /** @var QueueManager $queue */
        $queue = $this->container->get(QueueManager::class);

        $success = $queue->retry($id);
        if (!$success) {
            $this->error("Pekerjaan gagal #{$id} tidak ditemukan di tabel failed_jobs.");
            return 1;
        }

        $this->success("Pekerjaan #{$id} berhasil dipindahkan kembali ke antrean utama untuk diproses ulang.");
        return 0;
    }
}
