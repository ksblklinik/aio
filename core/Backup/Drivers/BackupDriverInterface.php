<?php

declare(strict_types=1);

namespace Core\Backup\Drivers;

interface BackupDriverInterface
{
    /**
     * Create an atomic, transactionally consistent snapshot of the live database.
     *
     * @param string $destinationPath Absolute file path where the raw snapshot should be written.
     * @return string Path to the written snapshot.
     */
    public function createSnapshot(string $destinationPath): string;

    /**
     * Restore the snapshot into the active database location.
     *
     * @param string $snapshotPath Path to the raw uncompressed snapshot file.
     * @return bool True if successfully restored.
     */
    public function restoreSnapshot(string $snapshotPath): bool;

    /**
     * Return the driver identifier (e.g. 'sqlite', 'mysql').
     */
    public function getDriverName(): string;

    /**
     * Return the live database file or connection path if applicable.
     */
    public function getDatabasePath(): ?string;
}
