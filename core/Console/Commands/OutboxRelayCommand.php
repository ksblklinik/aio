<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Outbox\TransactionalOutboxManagerInterface;
use Throwable;

class OutboxRelayCommand extends Command
{
    protected string $signature = 'outbox:relay';
    protected string $description = 'Kirimkan (relay) kumpulan event tertunda di Transactional Outbox ke SSE, Webhooks, dan Listeners';

    public function handle(array $arguments = [], array $options = []): int
    {
        /** @var TransactionalOutboxManagerInterface $outbox */
        $outbox = $this->container->get(TransactionalOutboxManagerInterface::class);

        $batch = isset($options['batch']) ? (int) $options['batch'] : 50;

        $this->info("Menjalankan relay event dari Transactional Outbox (Batch size: {$batch})...");

        try {
            $result = $outbox->relay($batch);

            $this->line(str_repeat('-', 70));
            $this->line(sprintf("%-25s: %d", "Event Berhasil Dikirim", $result['published']));
            $this->line(sprintf("%-25s: %d", "Event Gagal Dipancarkan", $result['failed']));
            $this->line(str_repeat('-', 70));

            if (!empty($result['errors'])) {
                $this->warn("Daftar Kesalahan Relay:");
                foreach ($result['errors'] as $err) {
                    $this->line(" ✖ " . $err);
                }
            }

            if ($result['published'] > 0) {
                $this->success(sprintf("Relay selesai: %d event berhasil dipublikasikan.", $result['published']));
            } else {
                $this->info("Tidak ada event pending yang perlu dikirim.");
            }

            return $result['failed'] > 0 ? 1 : 0;
        } catch (Throwable $e) {
            $this->error("Gagal menjalankan outbox relay: " . $e->getMessage());
            return 1;
        }
    }
}
