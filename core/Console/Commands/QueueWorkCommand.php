<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Queue\Job;
use Core\Queue\JobInterface;
use Core\Queue\QueueManager;
use Throwable;

class QueueWorkCommand extends Command
{
    protected string $signature = 'queue:work';
    protected string $description = 'Jalankan worker untuk memproses pekerjaan dari antrean';

    public function handle(array $arguments = [], array $options = []): int
    {
        /** @var QueueManager $queue */
        $queue = $this->container->get(QueueManager::class);

        $queueName = (string) ($options['queue'] ?? 'default');
        $runOnce = isset($options['once']) && $options['once'] !== false;
        $maxTries = isset($options['tries']) ? (int) $options['tries'] : 3;
        $sleepSeconds = isset($options['sleep']) ? (int) $options['sleep'] : 2;

        $this->info("Menjalankan Queue Worker pada antrean [{$queueName}]...");

        while (true) {
            $job = $queue->pop($queueName);

            if ($job !== null) {
                $this->processJob($queue, $job, $maxTries);
            } else {
                if ($runOnce) {
                    $this->line("Tidak ada pekerjaan tersisa pada antrean [{$queueName}]. Worker berhenti.");
                    break;
                }
                sleep($sleepSeconds);
            }

            if ($runOnce) {
                break;
            }
        }

        return 0;
    }

    private function processJob(QueueManager $queue, Job $job, int $globalMaxTries): void
    {
        $jobClass = $job->getJobClass();
        $jobId = $job->getId();
        $attempts = $job->getAttempts();
        $maxTries = $job->getMaxTries() ?: $globalMaxTries;

        $this->line("⚡ [Pekerjaan #{$jobId}] Memproses: {$jobClass} (Percobaan {$attempts}/{$maxTries})...");

        try {
            if (!class_exists($jobClass)) {
                throw new \RuntimeException("Kelas job [{$jobClass}] tidak ditemukan.");
            }

            $jobInstance = $this->container->get($jobClass);
            if (!$jobInstance instanceof JobInterface) {
                throw new \RuntimeException("Kelas job [{$jobClass}] tidak mengimplementasikan JobInterface.");
            }

            $jobInstance->handle($job->getData(), $this->container);

            $queue->delete($job);
            $this->success("✔ [Pekerjaan #{$jobId}] Selesai diproses.");
        } catch (Throwable $e) {
            $this->error("✖ [Pekerjaan #{$jobId}] Gagal: " . $e->getMessage());

            if ($attempts >= $maxTries) {
                $failedId = $queue->fail($job, $e);
                $this->warn("⚠ [Pekerjaan #{$jobId}] Mencapai batas maksimal percobaan ({$maxTries}). Dipindahkan ke failed_jobs #{$failedId}.");
            } else {
                $delay = $attempts * 5;
                $queue->release($job, $delay);
                $this->info("ℹ [Pekerjaan #{$jobId}] Dilepaskan kembali ke antrean untuk dicoba lagi dalam {$delay} detik.");
            }
        }
    }
}
