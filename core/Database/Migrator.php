<?php

declare(strict_types=1);

namespace Core\Database;

class Migrator
{
    public function __construct(private Connection $db)
    {
    }

    public function createMigrationsTable(): void
    {
        $this->db->statement("
            CREATE TABLE IF NOT EXISTS migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration TEXT NOT NULL UNIQUE,
                batch INTEGER NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
        ");
    }

    public function hasRun(string $migrationName): bool
    {
        $row = $this->db->selectOne("SELECT id FROM migrations WHERE migration = ?", [$migrationName]);
        return $row !== null;
    }

    public function runMigration(string $migrationName, string $sql, int $batch = 1): void
    {
        if ($this->hasRun($migrationName)) {
            return;
        }

        $this->db->transaction(function (Connection $conn) use ($migrationName, $sql, $batch) {
            $conn->exec($sql);
            $conn->insert('migrations', [
                'migration' => $migrationName,
                'batch' => $batch,
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
    public function runMigrationsFrom(string $directory): array
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
        $batch = $this->getNextBatch();

        foreach ($files as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            if (!$this->hasRun($name)) {
                $sql = file_get_contents($file);
                if ($sql !== false && trim($sql) !== '') {
                    $this->runMigration($name, $sql, $batch);
                    $executed[] = $name;
                }
            }
        }

        return $executed;
    }
}
