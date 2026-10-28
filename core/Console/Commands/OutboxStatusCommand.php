<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Outbox\TransactionalOutboxManagerInterface;
use Throwable;

class OutboxStatusCommand extends Command
{
    protected string $signature = 'outbox:status';
    protected string $description = 'Tampilkan statistik status event (pending, published, failed) di Transactional Outbox';

    public function handle(array $arguments = [], array $options = []): int
    {
        /** @var TransactionalOutboxManagerInterface $outbox */
        $outbox = $this->container->get(TransactionalOutboxManagerInterface::class);

        $tenantId = isset($options['tenant']) ? (int) $options['tenant'] : null;

        try {
            $stats = $outbox->getStats($tenantId);

            $this->info("Statistik Transactional Outbox:");
            if ($tenantId !== null) {
                $this->line("Filter Tenant ID: {$tenantId}");
            }
            $this->line(str_repeat('-', 60));
            $this->line(sprintf("%-25s: %d", "Total Event Terdaftar", $stats['total']));
            $this->line(sprintf("%-25s: \033[33m%d\033[0m", "Tertunda (Pending)", $stats['pending']));
            $this->line(sprintf("%-25s: \033[32m%d\033[0m", "Terpublikasi (Published)", $stats['published']));
            $this->line(sprintf("%-25s: \033[31m%d\033[0m", "Gagal Permanen (Failed)", $stats['failed']));
            $this->line(str_repeat('-', 60));

            return 0;
        } catch (Throwable $e) {
            $this->error("Gagal membaca status outbox: " . $e->getMessage());
            return 1;
        }
    }
}
