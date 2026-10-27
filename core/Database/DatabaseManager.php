<?php

declare(strict_types=1);

namespace Core\Database;

use InvalidArgumentException;

class DatabaseManager
{
    /**
     * @var array<string, Connection>
     */
    private array $connections = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $configs = [];

    private string $defaultConnection;

    public function __construct(string $default = 'sqlite', array $configs = [])
    {
        $this->defaultConnection = $default;
        $this->configs = $configs;
    }

    public function connection(?string $name = null): Connection
    {
        $name = $name ?? $this->defaultConnection;

        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        if (!isset($this->configs[$name])) {
            throw new InvalidArgumentException("Database connection [{$name}] is not configured.");
        }

        $connection = Connection::fromConfig($this->configs[$name]);
        $this->connections[$name] = $connection;

        return $connection;
    }

    public function setConnection(string $name, Connection $connection): self
    {
        $this->connections[$name] = $connection;
        return $this;
    }

    public function addConfig(string $name, array $config): self
    {
        $this->configs[$name] = $config;
        return $this;
    }

    public function getDefaultConnection(): string
    {
        return $this->defaultConnection;
    }

    public function setDefaultConnection(string $name): self
    {
        $this->defaultConnection = $name;
        return $this;
    }
}
