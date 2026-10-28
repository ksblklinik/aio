<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\Command;
use Core\Lock\LockManagerInterface;

class LockListCommand extends Command
{
    protected string $signature = 'lock:list';
    protected string $description = 'Tampilkan seluruh atomic lock yang sedang aktif di sistem';

    public function handle(array $arguments = [], array $options = []): int
    {
        /** @var LockManagerInterface $lockManager */
        $lockManager = $this->container->get(LockManagerInterface::class);

        $activeLocks = $lockManager->listActiveLocks();

        if (empty($activeLocks)) {
            $this->info("Tidak ada atomic lock yang sedang aktif saat ini.");
            return 0;
        }

        $this->info("Menampilkan " . count($activeLocks) . " atomic lock aktif:");
        $this->line(str_repeat('-', 80));
        $this->line(sprintf("%-35s | %-20s | %-15s", "RESOURCE KEY", "OWNER", "REMAINING (SEC)"));
        $this->line(str_repeat('-', 80));

        foreach ($activeLocks as $lock) {
            $this->line(sprintf(
                "%-35s | %-20s | %-15d",
                substr($lock['key'], 0, 35),
                substr($lock['owner'], 0, 20),
                $lock['remaining_seconds']
            ));
        }
        $this->line(str_repeat('-', 80));

        return 0;
    }
}
