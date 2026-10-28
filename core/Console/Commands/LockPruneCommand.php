<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Lock\LockManagerInterface;

class LockPruneCommand extends Command
{
    protected string $signature = 'lock:prune';
    protected string $description = 'Bersihkan seluruh rekaman atomic mutex lock yang sudah kedaluwarsa';

    public function handle(array $arguments = [], array $options = []): int
    {
        /** @var LockManagerInterface $lockManager */
        $lockManager = $this->container->get(LockManagerInterface::class);

        $this->line("Memeriksa dan membersihkan lock kedaluwarsa...");
        $pruned = $lockManager->pruneExpired();

        $this->info("✓ Berhasil memangkas {$pruned} lock usang dari sistem.");

        return 0;
    }
}
