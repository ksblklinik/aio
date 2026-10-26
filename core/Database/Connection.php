<?php

declare(strict_types=1);

namespace Core\Database;

use PDO;
use PDOException;
use Throwable;

class Connection
{
    private ?PDO $pdo = null;
    private int $transactionLevel = 0;

    public function __construct(string $dsn, ?string $username = null, ?string $password = null, array $options = [])
    {
        $defaultOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $this->pdo = new PDO($dsn, $username, $password, array_replace($defaultOptions, $options));
        $this->initializePragmas($dsn);
    }

    private function initializePragmas(string $dsn): void
    {
        if (str_starts_with($dsn, 'sqlite:')) {
            $this->pdo->exec("PRAGMA foreign_keys = ON;");
            $this->pdo->exec("PRAGMA busy_timeout = 5000;");
            if (!str_contains($dsn, ':memory:')) {
                $this->pdo->exec("PRAGMA journal_mode = WAL;");
            }
        }
    }

    public static function fromConfig(array $config): self
    {
        $driver = $config['driver'] ?? 'sqlite';

        if ($driver === 'sqlite') {
            $database = $config['database'] ?? ':memory:';
            $dsn = "sqlite:{$database}";
            return new self($dsn);
        }

        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? ($driver === 'pgsql' ? 5432 : 3306);
        $database = $config['database'] ?? 'aio';
        $username = $config['username'] ?? 'root';
        $password = $config['password'] ?? '';

        $dsn = "{$driver}:host={$host};port={$port};dbname={$database};charset=utf8mb4";
        return new self($dsn, $username, $password);
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function close(): void
    {
        // @phpstan-ignore-next-line
        $this->pdo = null;
    }

    public function statement(string $sql, array $params = []): bool
    {
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    public function exec(string $sql): int|false
    {
        return $this->pdo->exec($sql);
    }

    public function selectAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function select(string $sql, array $params = []): array
    {
        return $this->selectAll($sql, $params);
    }

    public function tableExists(string $tableName): bool
    {
        $res = $this->selectOne(
            "SELECT name FROM sqlite_master WHERE type='table' AND name = ?",
            [$tableName]
        );
        return $res !== null;
    }

    public function selectOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function insert(string $table, array $data): string
    {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $this->statement($sql, array_values($data));
        return (string) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where = '', array $whereParams = []): int
    {
        $sets = [];
        $values = [];

        foreach ($data as $col => $val) {
            $sets[] = "{$col} = ?";
            $values[] = $val;
        }

        $sql = "UPDATE {$table} SET " . implode(', ', $sets);
        if ($where !== '') {
            $sql .= " WHERE {$where}";
            $values = array_merge($values, $whereParams);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
        return $stmt->rowCount();
    }

    public function delete(string $table, string $where = '', array $whereParams = []): int
    {
        $sql = "DELETE FROM {$table}";
        if ($where !== '') {
            $sql .= " WHERE {$where}";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($whereParams);
        return $stmt->rowCount();
    }

    /**
     * Eksekusi callback di dalam transaksi database dengan dukungan nested savepoints dan rollback Throwable
     */
    public function transaction(callable $callback): mixed
    {
        if ($this->transactionLevel === 0) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec("SAVEPOINT trans_{$this->transactionLevel}");
        }

        $this->transactionLevel++;

        try {
            $result = $callback($this);
            $this->transactionLevel--;

            if ($this->transactionLevel === 0) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec("RELEASE SAVEPOINT trans_{$this->transactionLevel}");
            }

            return $result;
        } catch (Throwable $e) {
            $this->transactionLevel--;

            if ($this->transactionLevel === 0) {
                $this->pdo->rollBack();
            } else {
                $this->pdo->exec("ROLLBACK TO SAVEPOINT trans_{$this->transactionLevel}");
            }

            throw $e;
        }
    }
}
