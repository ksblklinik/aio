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
}
