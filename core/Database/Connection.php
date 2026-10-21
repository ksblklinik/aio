<?php

declare(strict_types=1);

namespace Core\Database;

use PDO;
use PDOException;

class Connection
{
    private PDO $pdo;

    public function __construct(string $dsn, ?string $username = null, ?string $password = null, array $options = [])
    {
        $defaultOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $this->pdo = new PDO($dsn, $username, $password, array_replace($defaultOptions, $options));
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

    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback($this);
            $this->pdo->commit();
            return $result;
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
