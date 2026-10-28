<?php

declare(strict_types=1);

namespace Core\Backup\SelfHealing;

use Core\Database\Connection;
use Throwable;

class DatabaseSelfHealer
{
    public function __construct(
        private Connection $db,
        private ?string $databasePath = null
    ) {
    }

    /**
     * Execute self-healing and performance optimization routine.
     *
     * @return array<string, mixed>
     */
    public function heal(): array
    {
        $startTime = microtime(true);
        $pdo = $this->db->getPdo();
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver !== 'sqlite') {
            return [
                'success' => true,
                'message' => "Self-healing not required for {$driver} driver.",
                'duration_ms' => 0,
            ];
        }

        $results = [
            'database_type' => 'sqlite',
            'actions_performed' => [],
            'initial_size_bytes' => ($this->databasePath && file_exists($this->databasePath)) ? filesize($this->databasePath) : 0,
            'initial_wal_bytes' => ($this->databasePath && file_exists($this->databasePath . '-wal')) ? filesize($this->databasePath . '-wal') : 0,
        ];

        // 1. Force WAL checkpoint and truncation
        try {
            $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE);');
            $results['actions_performed'][] = 'wal_checkpoint_truncate';
            $results['wal_checkpoint'] = 'SUCCESS';
        } catch (Throwable $e) {
            $results['wal_checkpoint'] = 'FAILED: ' . $e->getMessage();
        }

        // 2. Rebuild all indexes (REINDEX)
        try {
            $pdo->exec('REINDEX;');
            $results['actions_performed'][] = 'reindex_all';
            $results['reindex'] = 'SUCCESS';
        } catch (Throwable $e) {
            $results['reindex'] = 'FAILED: ' . $e->getMessage();
        }

        // 3. Compact and defragment database file (VACUUM)
        try {
            $pdo->exec('VACUUM;');
            $results['actions_performed'][] = 'vacuum_compact';
            $results['vacuum'] = 'SUCCESS';
        } catch (Throwable $e) {
            $results['vacuum'] = 'FAILED: ' . $e->getMessage();
        }

        // 4. Update query planner statistics
        try {
            $pdo->exec('PRAGMA optimize;');
            $results['actions_performed'][] = 'pragma_optimize';
            $results['optimize'] = 'SUCCESS';
        } catch (Throwable $e) {
            $results['optimize'] = 'FAILED: ' . $e->getMessage();
        }

        // 5. Final integrity check after healing
        $integrityErrors = [];
        try {
            $stmt = $pdo->query('PRAGMA integrity_check;');
            if ($stmt) {
                while ($row = $stmt->fetchColumn()) {
                    if (is_string($row) && strtolower(trim($row)) !== 'ok') {
                        $integrityErrors[] = $row;
                    }
                }
            }
        } catch (Throwable $e) {
            $integrityErrors[] = $e->getMessage();
        }

        $finalSize = ($this->databasePath && file_exists($this->databasePath)) ? filesize($this->databasePath) : 0;
        $finalWal = ($this->databasePath && file_exists($this->databasePath . '-wal')) ? filesize($this->databasePath . '-wal') : 0;

        $results['final_size_bytes'] = $finalSize;
        $results['final_wal_bytes'] = $finalWal;
        $results['freed_bytes'] = max(0, $results['initial_size_bytes'] - $finalSize);
        $results['is_healthy'] = empty($integrityErrors);
        $results['integrity_errors'] = $integrityErrors;
        $results['duration_ms'] = round((microtime(true) - $startTime) * 1000, 2);

        return $results;
    }
}
