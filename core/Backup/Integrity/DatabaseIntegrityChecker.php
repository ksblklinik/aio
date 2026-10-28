<?php

declare(strict_types=1);

namespace Core\Backup\Integrity;

use Core\Database\Connection;
use Throwable;

class DatabaseIntegrityChecker
{
    public function __construct(
        private Connection $db,
        private ?string $databasePath = null
    ) {
    }

    public function check(): IntegrityReport
    {
        $pdo = $this->db->getPdo();
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            return $this->checkSqlite();
        }

        return $this->checkGeneric($driver);
    }

    private function checkSqlite(): IntegrityReport
    {
        $integrityErrors = [];
        $foreignKeyViolations = [];
        $diagnostics = [];
        $tableDetails = [];
        $totalRecordCount = 0;

        try {
            // 1. Run PRAGMA integrity_check
            $stmt = $this->db->getPdo()->query('PRAGMA integrity_check;');
            if ($stmt) {
                while ($row = $stmt->fetchColumn()) {
                    if (is_string($row) && strtolower(trim($row)) !== 'ok') {
                        $integrityErrors[] = $row;
                    }
                }
            }

            // 2. Run PRAGMA foreign_key_check
            $fkStmt = $this->db->getPdo()->query('PRAGMA foreign_key_check;');
            if ($fkStmt) {
                while ($fkRow = $fkStmt->fetch(\PDO::FETCH_ASSOC)) {
                    $foreignKeyViolations[] = $fkRow;
                }
            }

            // 3. Inspect user tables and count records
            $tablesStmt = $this->db->getPdo()->query(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name ASC;"
            );
            $tables = $tablesStmt ? $tablesStmt->fetchAll(\PDO::FETCH_COLUMN) : [];

            foreach ($tables as $table) {
                try {
                    $cntRow = $this->db->selectOne("SELECT COUNT(*) AS total FROM \"{$table}\"");
                    $cnt = (int) ($cntRow['total'] ?? 0);
                    $tableDetails[$table] = $cnt;
                    $totalRecordCount += $cnt;
                } catch (Throwable) {
                    $tableDetails[$table] = -1;
                }
            }

            // 4. Capture engine diagnostics
            $journalMode = $this->db->getPdo()->query('PRAGMA journal_mode;')->fetchColumn();
            $pageSize = (int) $this->db->getPdo()->query('PRAGMA page_size;')->fetchColumn();
            $pageCount = (int) $this->db->getPdo()->query('PRAGMA page_count;')->fetchColumn();
            $freelistCount = (int) $this->db->getPdo()->query('PRAGMA freelist_count;')->fetchColumn();

            $diagnostics = [
                'journal_mode' => $journalMode,
                'page_size' => $pageSize,
                'page_count' => $pageCount,
                'freelist_count' => $freelistCount,
                'database_file' => $this->databasePath,
                'file_size_bytes' => ($this->databasePath && file_exists($this->databasePath)) ? filesize($this->databasePath) : 0,
                'wal_size_bytes' => ($this->databasePath && file_exists($this->databasePath . '-wal')) ? filesize($this->databasePath . '-wal') : 0,
            ];

        } catch (Throwable $e) {
            $integrityErrors[] = 'Exception during integrity check: ' . $e->getMessage();
        }

        $isHealthy = empty($integrityErrors) && empty($foreignKeyViolations);

        return new IntegrityReport(
            isHealthy: $isHealthy,
            databaseType: 'sqlite',
            integrityCheckErrors: $integrityErrors,
            foreignKeyViolations: $foreignKeyViolations,
            tableCount: count($tableDetails),
            totalRecordCount: $totalRecordCount,
            tableDetails: $tableDetails,
            diagnostics: $diagnostics,
            timestamp: date('c')
        );
    }

    private function checkGeneric(string $driver): IntegrityReport
    {
        return new IntegrityReport(
            isHealthy: true,
            databaseType: $driver,
            integrityCheckErrors: [],
            foreignKeyViolations: [],
            tableCount: 0,
            totalRecordCount: 0,
            tableDetails: [],
            diagnostics: ['driver' => $driver],
            timestamp: date('c')
        );
    }
}
