<?php

declare(strict_types=1);

namespace Core\Backup\Drivers;

use Core\Database\Connection;
use RuntimeException;
use Throwable;

class SqliteBackupDriver implements BackupDriverInterface
{
    public function __construct(
        private Connection $db,
        private ?string $databasePath = null
    ) {
        if ($this->databasePath === null) {
            $this->databasePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'database.sqlite';
        }
    }

    public function createSnapshot(string $destinationPath): string
    {
        $dir = dirname($destinationPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (file_exists($destinationPath)) {
            @unlink($destinationPath);
        }

        $pdo = $this->db->getPdo();

        // 1. Flush any pending WAL writes to main database file
        try {
            $pdo->exec('PRAGMA wal_checkpoint(PASSIVE);');
        } catch (\Throwable $e) {
            \Core\Log\Logger::getInstance()->warning(static::class . ": " . $e->getMessage());
        }

        // 2. Atomic VACUUM INTO produces an isolated, consistent database snapshot
        $quoted = $pdo->quote($destinationPath);
        $result = $pdo->exec("VACUUM INTO {$quoted};");

        if (!file_exists($destinationPath)) {
            throw new RuntimeException("Gagal membuat snapshot SQLite di: {$destinationPath}");
        }

        return $destinationPath;
    }

    public function restoreSnapshot(string $snapshotPath): bool
    {
        if (!file_exists($snapshotPath)) {
            throw new RuntimeException("Berkas snapshot untuk restore tidak ditemukan: {$snapshotPath}");
        }

        $targetPath = $this->databasePath;
        if ($targetPath === null || $targetPath === ':memory:' || str_contains($targetPath, ':memory:')) {
            // In-memory test environment: copy tables from snapshot
            return $this->restoreInMemory($snapshotPath);
        }

        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $safetyCopy = $targetPath . '.pre_restore_safety_' . time();
        $safetyWal = $targetPath . '-wal.pre_restore_safety_' . time();

        // 1. Create safety backup of active database
        if (file_exists($targetPath)) {
            copy($targetPath, $safetyCopy);
        }
        if (file_exists($targetPath . '-wal')) {
            copy($targetPath . '-wal', $safetyWal);
        }

        try {
            // 2. Truncate WAL on live DB before replacement
            try {
                $this->db->getPdo()->exec('PRAGMA wal_checkpoint(TRUNCATE);');
            } catch (\Throwable $e) {
            \Core\Log\Logger::getInstance()->warning(static::class . ": " . $e->getMessage());
        }

            // Release database file locks on active connection
            $this->db->close();

            // 3. Copy snapshot over target database file
            if (!copy($snapshotPath, $targetPath)) {
                throw new RuntimeException("Gagal menyalin berkas snapshot ke: {$targetPath}");
            }

            // 4. Remove stale WAL and SHM files so restored snapshot takes immediate effect
            if (file_exists($targetPath . '-wal')) {
                @unlink($targetPath . '-wal');
            }
            if (file_exists($targetPath . '-shm')) {
                @unlink($targetPath . '-shm');
            }

            // 5. Reconnect and verify integrity of newly restored database file
            $this->db->reconnect();
            $check = $this->db->getPdo()->query('PRAGMA quick_check;')->fetchColumn();
            if (is_string($check) && strtolower(trim($check)) !== 'ok') {
                throw new RuntimeException("Verifikasi integritas database hasil restore gagal: {$check}");
            }

            // Clean up safety copies on successful restore
            @unlink($safetyCopy);
            @unlink($safetyWal);

            return true;
        } catch (Throwable $e) {
            $this->db->close();
            // Rollback from safety copy if replacement failed
            if (file_exists($safetyCopy)) {
                copy($safetyCopy, $targetPath);
                @unlink($safetyCopy);
            }
            if (file_exists($safetyWal)) {
                copy($safetyWal, $targetPath . '-wal');
                @unlink($safetyWal);
            }
            $this->db->reconnect();
            throw new RuntimeException("Gagal memulihkan database dari snapshot: " . $e->getMessage(), 0, $e);
        }
    }

    private function restoreInMemory(string $snapshotPath): bool
    {
        $pdo = $this->db->getPdo();
        $quoted = $pdo->quote($snapshotPath);

        // Attach snapshot and transfer data
        $pdo->exec("ATTACH DATABASE {$quoted} AS snap_db;");

        $tablesStmt = $pdo->query(
            "SELECT name FROM snap_db.sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%';"
        );
        $tables = $tablesStmt ? $tablesStmt->fetchAll(\PDO::FETCH_COLUMN) : [];

        $pdo->exec('PRAGMA foreign_keys = OFF;');
        foreach ($tables as $t) {
            $pdo->exec("DROP TABLE IF EXISTS main.\"{$t}\";");
            $pdo->exec("CREATE TABLE main.\"{$t}\" AS SELECT * FROM snap_db.\"{$t}\";");
        }
        $pdo->exec('PRAGMA foreign_keys = ON;');

        $pdo->exec('DETACH DATABASE snap_db;');
        return true;
    }

    public function getDriverName(): string
    {
        return 'sqlite';
    }

    public function getDatabasePath(): ?string
    {
        return $this->databasePath;
    }
}
