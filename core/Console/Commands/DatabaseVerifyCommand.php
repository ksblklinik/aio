<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Backup\BackupManagerInterface;
use Core\Console\Command;
use Throwable;

class DatabaseVerifyCommand extends Command
{
    protected string $signature = 'db:verify';
    protected string $description = 'Audit menyeluruh integritas fisik, relasi foreign key, dan kesehatan database';

    public function handle(array $arguments = [], array $options = []): int
    {
        /** @var BackupManagerInterface $backupManager */
        $backupManager = $this->container->get(BackupManagerInterface::class);

        $this->info("Menjalankan audit diagnostik integritas database...");

        try {
            $report = $backupManager->verify();

            $this->line(str_repeat('-', 75));
            $this->line(sprintf("%-25s: %s", "Tipe Database", strtoupper($report->databaseType)));
            $this->line(sprintf("%-25s: %s", "Status Integritas", $report->isHealthy ? '100% HEALTHY (SEHAT)' : 'ADA PERINGATAN / RUSAK'));
            $this->line(sprintf("%-25s: %d tabel", "Total Tabel Pengguna", $report->tableCount));
            $this->line(sprintf("%-25s: %d baris", "Total Baris Rekaman", $report->totalRecordCount));

            if (!empty($report->diagnostics)) {
                $diag = $report->diagnostics;
                if (isset($diag['journal_mode'])) {
                    $this->line(sprintf("%-25s: %s", "Journal Mode", strtoupper((string) $diag['journal_mode'])));
                }
                if (isset($diag['file_size_bytes'])) {
                    $mb = sprintf('%.2f MB', $diag['file_size_bytes'] / 1048576);
                    $this->line(sprintf("%-25s: %s (%d bytes)", "Ukuran Berkas Utama", $mb, $diag['file_size_bytes']));
                }
                if (isset($diag['wal_size_bytes']) && $diag['wal_size_bytes'] > 0) {
                    $walMb = sprintf('%.2f MB', $diag['wal_size_bytes'] / 1048576);
                    $this->line(sprintf("%-25s: %s", "Ukuran Berkas WAL", $walMb));
                }
            }

            $this->line(str_repeat('-', 75));

            if (!$report->isHealthy) {
                if (!empty($report->integrityCheckErrors)) {
                    $this->error("Kesalahan Integritas B-Tree / Halaman:");
                    foreach ($report->integrityCheckErrors as $err) {
                        $this->line(" - {$err}");
                    }
                }
                if (!empty($report->foreignKeyViolations)) {
                    $this->error("Pelanggaran Relasi Foreign Key:");
                    foreach ($report->foreignKeyViolations as $fk) {
                        $this->line(" - " . json_encode($fk));
                    }
                }
                return 1;
            }

            $this->info($report->getSummary());
            return 0;
        } catch (Throwable $e) {
            $this->error("Gagal melakukan verifikasi database: " . $e->getMessage());
            return 1;
        }
    }
}
