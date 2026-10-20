<?php

declare(strict_types=1);

namespace Core\Database;

class TenantContext
{
    private static ?TenantContext $instance = null;

    private ?string $tenantId = null;
    private array $tenantData = [];

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    public function setTenantId(?string $id, array $data = []): void
    {
        $this->tenantId = $id;
        $this->tenantData = $data;
    }

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null && $this->tenantId !== '';
    }

    public function getTenantData(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->tenantData;
        }
        return $this->tenantData[$key] ?? $default;
    }

    public function clear(): void
    {
        $this->tenantId = null;
        $this->tenantData = [];
    }
}
