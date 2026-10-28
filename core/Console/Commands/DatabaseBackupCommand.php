<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Backup\BackupManagerInterface;
use Core\Console\Command;
use Throwable;

class DatabaseBackupCommand extends Command
{
    protected string $signature = 'db:backup';
    protected string $description = 'Buat online hot-backup database atomik secara aman tanpa downtime';

    public function handle(array $arguments = [], array $options = []): int
    {
        /** @var BackupManagerInterface $backupManager */
        $backupManager = $this->container->get(BackupManagerInterface::class);

        $compress = !isset($options['no-compress']);
        $encrypt = isset($options['encrypt']);
        $tag = $options['tag'] ?? null;

        $this->info("Memulai pembuatan online hot-backup...");
        $startTime = microtime(true);

        try {
            $package = $backupManager->backup([
                'compress' => $compress,
                'encrypt' => $encrypt,
                'tag' => $tag,
            ]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $this->info("✓ Hot-backup berhasil diselesaikan dalam {$duration} ms!");
            $this->line(str_repeat('-', 75));
            $this->line(sprintf("%-20s: %s", "Backup ID", $package->backupId));
            $this->line(sprintf("%-20s: %s", "Berkas Paket", basename($package->filePath)));
            $this->line(sprintf("%-20s: %s", "Lokasi", $package->filePath));
            $this->line(sprintf("%-20s: %s", "Ukuran Paket", $package->toArray()['formatted_size']));
            $this->line(sprintf("%-20s: %s", "Kompresi", $package->isCompressed ? 'Gzip (Tinggi)' : 'Tidak'));
            $this->line(sprintf("%-20s: %s", "Enkripsi", $package->isEncrypted ? 'AES-256-GCM (Terotentikasi)' : 'Tidak'));
            $this->line(sprintf("%-20s: %s", "SHA-256 Checksum", $package->sha256));
            $this->line(str_repeat('-', 75));

            return 0;
        } catch (Throwable $e) {
            $this->error("Gagal membuat backup database: " . $e->getMessage());
            return 1;
        }
    }
}
