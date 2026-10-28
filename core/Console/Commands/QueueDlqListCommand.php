<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Queue\DeadLetterQueueInterface;
use Throwable;

class QueueDlqListCommand extends Command
{
    protected string $signature = 'queue:dlq:list';
    protected string $description = 'Daftar pekerjaan gagal yang tersimpan di Dead-Letter Queue (DLQ)';

    public function handle(array $arguments = [], array $options = []): int
    {
        /** @var DeadLetterQueueInterface $dlq */
        $dlq = $this->container->get(DeadLetterQueueInterface::class);

        $queue = isset($options['queue']) ? (string) $options['queue'] : null;
        $limit = isset($options['limit']) ? (int) $options['limit'] : 20;

        try {
            $totalCount = $dlq->count($queue);
            $failedJobs = $dlq->list($queue, $limit);

            if (empty($failedJobs)) {
                $this->success("Dead-Letter Queue bersih. Tidak ada pekerjaan gagal.");
                return 0;
            }

            $this->warn(sprintf("Menampilkan %d dari total %d pekerjaan gagal di Dead-Letter Queue:", count($failedJobs), $totalCount));
            $this->line(str_repeat('-', 90));
            $this->line(sprintf(
                "%-6s | %-12s | %-25s | %-20s | %s",
                "ID",
                "Antrean",
                "Kelas Job",
                "Waktu Gagal",
                "Pesan Error"
            ));
            $this->line(str_repeat('-', 90));

            foreach ($failedJobs as $job) {
                $payload = json_decode((string) $job['payload'], true) ?: [];
                $jobClass = $payload['job'] ?? 'UnknownJob';
                $shortJob = basename(str_replace('\\', '/', (string) $jobClass));
                $firstLine = strtok((string) $job['exception'], "\n") ?: '';
                $shortError = mb_substr($firstLine, 0, 30) . (mb_strlen($firstLine) > 30 ? '...' : '');

                $this->line(sprintf(
                    "%-6d | %-12s | %-25s | %-20s | %s",
                    $job['id'],
                    $job['queue'],
                    $shortJob,
                    $job['failed_at'],
                    $shortError
                ));
            }

            $this->line(str_repeat('-', 90));
            $this->info("Gunakan 'php aio queue:dlq:retry <id>' atau 'php aio queue:dlq:retry --all' untuk mencoba kembali.");
            return 0;
        } catch (Throwable $e) {
            $this->error("Gagal membaca Dead-Letter Queue: " . $e->getMessage());
            return 1;
        }
    }
}
