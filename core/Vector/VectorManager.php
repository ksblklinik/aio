<?php

declare(strict_types=1);

namespace Core\Vector;

use InvalidArgumentException;

class VectorManager
{
    /**
     * @var array<string, VectorStoreInterface>
     */
    private array $drivers = [];

    public function __construct(
        private VectorStoreInterface $defaultDriver,
        private string $defaultName = 'sqlite'
    ) {
        $this->drivers[$defaultName] = $defaultDriver;
    }

    public function driver(?string $name = null): VectorStoreInterface
    {
        $name = $name ?? $this->defaultName;
        if (!isset($this->drivers[$name])) {
            throw new InvalidArgumentException("Vector driver [{$name}] is not configured.");
        }

        return $this->drivers[$name];
    }

    public function setDriver(string $name, VectorStoreInterface $driver): self
    {
        $this->drivers[$name] = $driver;
        return $this;
    }

    public function upsert(
        string $collection,
        string $itemId,
        array $vector,
        array $metadata = [],
        ?int $tenantId = null
    ): bool {
        return $this->driver()->upsert($collection, $itemId, $vector, $metadata, $tenantId);
    }

    public function search(
        string $collection,
        array $queryVector,
        int $limit = 5,
        float $minScore = 0.0,
        ?int $tenantId = null
    ): array {
        return $this->driver()->search($collection, $queryVector, $limit, $minScore, $tenantId);
    }

    public function delete(string $collection, string $itemId, ?int $tenantId = null): bool
    {
        return $this->driver()->delete($collection, $itemId, $tenantId);
    }

    public function deleteCollection(string $collection, ?int $tenantId = null): int
    {
        return $this->driver()->deleteCollection($collection, $tenantId);
    }
}
