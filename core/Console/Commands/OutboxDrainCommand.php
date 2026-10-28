<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Outbox\TransactionalOutboxManagerInterface;
use Throwable;

class OutboxDrainCommand extends Command
{
    protected string $signature = 'outbox:drain';
    protected string $description = 'Kuras (drain) seluruh event tertunda di Transactional Outbox hingga tuntas';

    public function handle(array $arguments = [], array $options = []): int
    {
        /** @var TransactionalOutboxManagerInterface $outbox */
        $outbox = $this->container->get(TransactionalOutboxManagerInterface::class);

        $this->info("Menguras seluruh event tertunda di Transactional Outbox...");

        try {
            $initialPending = $outbox->getPendingCount();
            if ($initialPending === 0) {
                $this->success("Transactional Outbox kosong. Tidak ada event pending.");
                return 0;
            }

            $totalPublished = $outbox->drainAll(50);
            $remaining = $outbox->getPendingCount();

            $this->line(str_repeat('-', 70));
            $this->line(sprintf("%-30s: %d", "Total Event Sebelum Drain", $initialPending));
            $this->line(sprintf("%-30s: %d", "Total Event Dipublikasikan", $totalPublished));
            $this->line(sprintf("%-30s: %d", "Sisa Event Tertunda", $remaining));
            $this->line(str_repeat('-', 70));

            if ($remaining === 0) {
                $this->success("Seluruh antrean Transactional Outbox berhasil dikuras 100%.");
            } else {
                $this->warn("Terdapat sisa event yang belum dapat dikirimkan (kemungkinan mencapai batas max_attempts).");
            }

            return 0;
        } catch (Throwable $e) {
            $this->error("Gagal menguras outbox: " . $e->getMessage());
            return 1;
        }
    }
}
