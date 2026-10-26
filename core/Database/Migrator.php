<?php

declare(strict_types=1);

namespace Core\Database;

use Core\Modules\ModuleManager;

class Migrator
{
    private Connection $db;
    private string $coreMigrationsPath;
    private ?ModuleManager $moduleManager;

    public function __construct(
        Connection $db,
        ?string $coreMigrationsPath = null,
        ?ModuleManager $moduleManager = null
    ) {
        $this->db = $db;
        $this->coreMigrationsPath = $coreMigrationsPath ?? dirname(__DIR__, 2) . '/database/migrations';
        $this->moduleManager = $moduleManager;
    }

    /**
     * Parse SQL file untuk memisahkan instruksi -- @up dan -- @down
     *
     * @return array{up: string, down: string}
     */
    public static function parseMigrationFile(string $content): array
    {
        if (preg_match('/--\s*@up/i', $content)) {
            $parts = preg_split('/--\s*@down/i', $content);
            $upPart = preg_replace('/--\s*@up/i', '', $parts[0] ?? '');
            $downPart = $parts[1] ?? '';

            return [
                'up' => trim((string) $upPart),
                'down' => trim((string) $downPart),
            ];
        }

        return [
            'up' => trim($content),
            'down' => '',
        ];
    }

    public function createMigrationsTable(): void
    {
        $this->db->statement("
            CREATE TABLE IF NOT EXISTS migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration TEXT NOT NULL UNIQUE,
                module TEXT NOT NULL DEFAULT 'core',
                batch INTEGER NOT NULL,
                down_sql TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
        ");

        try {
            $cols = $this->db->select("PRAGMA table_info(migrations)");
            $colNames = array_column($cols, 'name');
            if (!in_array('module', $colNames, true)) {
                $this->db->statement("ALTER TABLE migrations ADD COLUMN module TEXT NOT NULL DEFAULT 'core'");
            }
            if (!in_array('down_sql', $colNames, true)) {
                $this->db->statement("ALTER TABLE migrations ADD COLUMN down_sql TEXT");
            }
        } catch (\Throwable) {
            // Abaikan untuk koneksi mock / non-sqlite
        }
    }

    public function hasRun(string $migrationName): bool
    {
        $row = $this->db->selectOne("SELECT id FROM migrations WHERE migration = ?", [$migrationName]);
        return $row !== null;
    }

    public function runMigration(string $migrationName, string $sql, int $batch = 1, string $module = 'core', string $downSql = ''): void
    {
        if ($this->hasRun($migrationName)) {
            return;
        }

        $parsed = self::parseMigrationFile($sql);
        $upSql = $parsed['up'];
        $finalDownSql = $downSql !== '' ? $downSql : $parsed['down'];

        $this->db->transaction(function (Connection $conn) use ($migrationName, $upSql, $batch, $module, $finalDownSql) {
            if (trim($upSql) !== '') {
                $conn->exec($upSql);
            }
            $conn->insert('migrations', [
                'migration' => $migrationName,
                'module' => $module,
                'batch' => $batch,
                'down_sql' => $finalDownSql,
            ]);
        });
    }

    public function getNextBatch(): int
    {
        $row = $this->db->selectOne("SELECT MAX(batch) as max_batch FROM migrations");
        return ((int) ($row['max_batch'] ?? 0)) + 1;
    }

    /**
     * @return array<string> List of executed migration names
     */
    public function runMigrationsFrom(string $directory, string $module = 'core', ?int $batch = null): array
    {
        $this->createMigrationsTable();

        if (!is_dir($directory)) {
            return [];
        }

        $files = glob(rtrim($directory, '/\\') . '/*.sql');
        if ($files === false || empty($files)) {
            return [];
        }

        sort($files);
        $executed = [];
        $batchNumber = $batch ?? $this->getNextBatch();

        foreach ($files as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            if (!$this->hasRun($name)) {
                $content = file_get_contents($file);
                if ($content !== false && trim($content) !== '') {
                    $this->runMigration($name, $content, $batchNumber, $module);
                    $executed[] = $name;
                }
            }
        }

        return $executed;
    }

    /**
     * Jalankan migrasi core dan seluruh modul aktif (atau modul tertentu jika dispesifikasikan)
     *
     * @return array<string>
     */
    public function runAllMigrations(?string $specificModule = null): array
    {
        $this->createMigrationsTable();
        $batch = $this->getNextBatch();
        $executed = [];

        // 1. Core migrations
        if ($specificModule === null || $specificModule === 'core') {
            $coreExecuted = $this->runMigrationsFrom($this->coreMigrationsPath, 'core', $batch);
            $executed = array_merge($executed, $coreExecuted);
        }

        // 2. Module migrations
        $moduleManager = $this->getModuleManager();
        if ($moduleManager) {
            $modules = $moduleManager->getActiveModules();
            foreach ($modules as $mod) {
                $modId = (string) $mod['id'];
                if ($specificModule !== null && $specificModule !== $modId) {
                    continue;
                }

                $modPath = $moduleManager->getModulePath($modId);
                if ($modPath && is_dir($modPath . '/Database/Migrations')) {
                    $modExecuted = $this->runMigrationsFrom($modPath . '/Database/Migrations', $modId, $batch);
                    $executed = array_merge($executed, $modExecuted);
                }
            }
        }

        return $executed;
    }

    /**
     * Rollback migrasi pada batch terakhir (opsional difilter berdasarkan modul)
     *
     * @return array<string>
     */
    public function rollbackLastBatch(?string $module = null): array
    {
        $this->createMigrationsTable();

        $whereClause = $module !== null ? "WHERE module = ?" : "";
        $params = $module !== null ? [$module] : [];

        $row = $this->db->selectOne("SELECT MAX(batch) as last_batch FROM migrations {$whereClause}", $params);
        $lastBatch = $row['last_batch'] ?? null;

        if ($lastBatch === null) {
            return [];
        }

        $query = "SELECT * FROM migrations WHERE batch = ?" . ($module !== null ? " AND module = ?" : "") . " ORDER BY id DESC";
        $queryParams = $module !== null ? [(int) $lastBatch, $module] : [(int) $lastBatch];
        $migrations = $this->db->select($query, $queryParams);

        $rolledBack = [];

        foreach ($migrations as $item) {
            $downSql = (string) ($item['down_sql'] ?? '');
            $migrationName = (string) $item['migration'];

            $this->db->transaction(function (Connection $conn) use ($item, $downSql) {
                if (trim($downSql) !== '') {
                    $conn->exec($downSql);
                }
                $conn->delete('migrations', 'id = ?', [(int) $item['id']]);
            });

            $rolledBack[] = $migrationName;
        }

        return $rolledBack;
    }

    /**
     * Rollback batch terakhir untuk modul spesifik
     *
     * @return array<string>
     */
    public function rollbackModule(string $module): array
    {
        return $this->rollbackLastBatch($module);
    }

    private function getModuleManager(): ?ModuleManager
    {
        if ($this->moduleManager !== null) {
            return $this->moduleManager;
        }

        if (function_exists('app') && app()->has(ModuleManager::class)) {
            return app(ModuleManager::class);
        }

        return null;
    }
}
