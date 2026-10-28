<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Backup\BackupManagerInterface;
use Core\Console\Command;
use Throwable;

class DatabasePruneBackupsCommand extends Command
{
    protected string $signature = 'db:prune-backups';
    protected string $description = 'Bersihkan paket cadangan lama sesuai kuota retensi yang ditentukan';

    public function handle(array $arguments = [], array $options = []): int
    {
        $keep = isset($options['keep']) ? (int) $options['keep'] : 10;
        if ($keep < 1) {
            $keep = 1;
        }

        /** @var BackupManagerInterface $backupManager */
        $backupManager = $this->container->get(BackupManagerInterface::class);

        $this->info("Menjalankan pembersihan arsip cadangan lama (menyimpan {$keep} cadangan terbaru)...");

        try {
            $pruned = $backupManager->prune($keep);
            if ($pruned > 0) {
                $this->info("✓ Berhasil memangkas {$pruned} arsip cadangan lama.");
            } else {
                $this->info("Tidak ada arsip cadangan lama yang perlu dipangkas.");
            }

            return 0;
        } catch (Throwable $e) {
            $this->error("Gagal memangkas arsip cadangan: " . $e->getMessage());
            return 1;
        }
    }
}
