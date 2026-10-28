<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Backup\BackupManagerInterface;
use Core\Console\Command;
use Throwable;

class DatabaseRestoreCommand extends Command
{
    protected string $signature = 'db:restore';
    protected string $description = 'Pulihkan database dari berkas paket cadangan dengan proteksi rollback otomatis';

    public function handle(array $arguments = [], array $options = []): int
    {
        $backupFile = $arguments[0] ?? null;
        if (!$backupFile) {
            $this->error("Target berkas cadangan wajib ditentukan. Contoh: php aio db:restore backup_2026-10-28.aio.gz");
            return 1;
        }

        $force = isset($options['force']);

        /** @var BackupManagerInterface $backupManager */
        $backupManager = $this->container->get(BackupManagerInterface::class);

        $this->info("Menyiapkan pemulihan database dari: {$backupFile}");
        $this->info("Menjalankan snapshot proteksi pra-restorasi...");

        try {
            $backupManager->restore($backupFile, $force);
            $this->info("✓ Database berhasil dipulihkan secara konsisten dan aman!");
            return 0;
        } catch (Throwable $e) {
            $this->error("Gagal memulihkan database: " . $e->getMessage());
            return 1;
        }
    }
}
