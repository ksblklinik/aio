<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Outbox\TransactionalOutboxManagerInterface;
use Throwable;

class OutboxPruneCommand extends Command
{
    protected string $signature = 'outbox:prune';
    protected string $description = 'Bersihkan arsip event lama yang sudah sukses terpublikasi dari Transactional Outbox';

    public function handle(array $arguments = [], array $options = []): int
    {
        /** @var TransactionalOutboxManagerInterface $outbox */
        $outbox = $this->container->get(TransactionalOutboxManagerInterface::class);

        $days = isset($options['days']) ? (int) $options['days'] : 7;

        $this->info("Membersihkan event outbox yang telah dipublikasikan lebih dari {$days} hari...");

        try {
            $deleted = $outbox->prune($days);
            $this->success(sprintf("Berhasil membersihkan %d event terpublikasi dari basis data outbox.", $deleted));
            return 0;
        } catch (Throwable $e) {
            $this->error("Gagal membersihkan outbox: " . $e->getMessage());
            return 1;
        }
    }
}
