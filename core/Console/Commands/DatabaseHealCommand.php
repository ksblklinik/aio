<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Backup\BackupManagerInterface;
use Core\Console\Command;
use Throwable;

class DatabaseHealCommand extends Command
{
    protected string $signature = 'db:heal';
    protected string $description = 'Jalankan pemulihan mandiri database: WAL checkpoint, reindex, vacuum, dan defragmentasi';

    public function handle(array $arguments = [], array $options = []): int
    {
        /** @var BackupManagerInterface $backupManager */
        $backupManager = $this->container->get(BackupManagerInterface::class);

        $this->info("Menjalankan prosedur pemulihan mandiri & optimasi database...");

        try {
            $result = $backupManager->heal();

            $this->line(str_repeat('-', 75));
            $this->line(sprintf("%-25s: %s", "Tindakan Dilakukan", implode(', ', $result['actions_performed'] ?? [])));
            $this->line(sprintf("%-25s: %s", "WAL Checkpoint", $result['wal_checkpoint'] ?? 'N/A'));
            $this->line(sprintf("%-25s: %s", "Reindex Status", $result['reindex'] ?? 'N/A'));
            $this->line(sprintf("%-25s: %s", "Vacuum & Defrag", $result['vacuum'] ?? 'N/A'));

            if (isset($result['freed_bytes']) && $result['freed_bytes'] > 0) {
                $kb = sprintf('%.2f KB', $result['freed_bytes'] / 1024);
                $this->line(sprintf("%-25s: %s (%d bytes)", "Ruang Disk Diresik", $kb, $result['freed_bytes']));
            }

            $this->line(sprintf("%-25s: %s ms", "Waktu Eksekusi", $result['duration_ms'] ?? 0));
            $this->line(str_repeat('-', 75));

            if (!empty($result['is_healthy'])) {
                $this->info("✓ Database berhasil dipulihkan dan dioptimasi secara maksimal!");
                return 0;
            }

            $this->error("Peringatan: Integritas database masih menunjukkan anomali.");
            return 1;
        } catch (Throwable $e) {
            $this->error("Gagal menjalankan self-healing database: " . $e->getMessage());
            return 1;
        }
    }
}
