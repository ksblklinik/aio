<?php

declare(strict_types=1);

namespace Core\Backup;

use Core\Backup\Integrity\IntegrityReport;

interface BackupManagerInterface
{
    /**
     * Create an online, consistent backup snapshot.
     *
     * @param array<string, mixed> $options ['compress' => bool, 'encrypt' => bool, 'tag' => ?string, 'key' => ?string]
     * @return BackupPackage
     */
    public function backup(array $options = []): BackupPackage;

    /**
     * Safely restore database from a backup package file with automated safety rollback.
     *
     * @param string $backupPath Path to backup package file or filename in backup directory.
     * @param bool $force If true, bypasses minor warnings.
     * @return bool
     */
    public function restore(string $backupPath, bool $force = false): bool;

    /**
     * Run full database integrity audits (corruptions, foreign keys, table record counts).
     */
    public function verify(): IntegrityReport;

    /**
     * Execute database self-healing procedures (WAL truncate, reindex, vacuum, page optimization).
     *
     * @return array<string, mixed>
     */
    public function heal(): array;

    /**
     * List all available backup packages in the backup storage directory.
     *
     * @return array<array<string, mixed>>
     */
    public function listBackups(): array;

    /**
     * Prune older backups according to retention policy.
     *
     * @param int $keepCount Number of most recent backups to retain.
     * @return int Number of pruned backups.
     */
    public function prune(int $keepCount = 10): int;

    /**
     * Return absolute path to backup storage directory.
     */
    public function getBackupDirectory(): string;
}
