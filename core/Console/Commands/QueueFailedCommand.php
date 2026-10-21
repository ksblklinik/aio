<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Queue\QueueManager;

class QueueFailedCommand extends Command
{
    protected string $signature = 'queue:failed';
    protected string $description = 'Menampilkan daftar pekerjaan yang gagal dieksekusi (Dead-Letter Queue)';

    public function handle(array $arguments = [], array $options = []): int
    {
        /** @var QueueManager $queue */
        $queue = $this->container->get(QueueManager::class);

        $limit = isset($options['limit']) ? (int) $options['limit'] : 20;
        $failedJobs = $queue->getFailedJobs($limit);

        if (empty($failedJobs)) {
            $this->success("Tidak ada pekerjaan yang gagal di antrean.");
            return 0;
        }

        $rows = [];
        foreach ($failedJobs as $job) {
            $payload = json_decode($job['payload'], true) ?: [];
            $jobClass = $payload['job'] ?? 'Unknown';

            $exceptionFirstLine = explode("\n", (string) $job['exception'])[0] ?? '';
            if (strlen($exceptionFirstLine) > 50) {
                $exceptionFirstLine = substr($exceptionFirstLine, 0, 47) . '...';
            }

            $rows[] = [
                'ID' => (string) $job['id'],
                'Antrean' => (string) $job['queue'],
                'Kelas Job' => $jobClass,
                'Penyebab Error' => $exceptionFirstLine,
                'Waktu Gagal' => (string) $job['failed_at'],
            ];
        }

        $this->table(['ID', 'Antrean', 'Kelas Job', 'Penyebab Error', 'Waktu Gagal'], $rows);
        $this->info("Total: " . count($rows) . " pekerjaan gagal. Gunakan 'php aio queue:retry <id>' untuk mencoba kembali.");

        return 0;
    }
}
