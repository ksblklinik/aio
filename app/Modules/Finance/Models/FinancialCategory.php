<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

class FinancialCategory
{
    public function __construct(
        public ?int $id = null,
        public int $tenantId = 0,
        public string $code = '',
        public string $name = '',
        public string $type = 'expense', // 'income', 'expense', 'asset', 'liability', 'equity'
        public ?int $parentId = null,
        public bool $isActive = true,
        public ?string $createdAt = null,
        public ?string $updatedAt = null
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            tenantId: (int) ($row['tenant_id'] ?? 0),
            code: (string) ($row['code'] ?? ''),
            name: (string) ($row['name'] ?? ''),
            type: (string) ($row['type'] ?? 'expense'),
            parentId: isset($row['parent_id']) && $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            isActive: (bool) ($row['is_active'] ?? true),
            createdAt: isset($row['created_at']) ? (string) $row['created_at'] : null,
            updatedAt: isset($row['updated_at']) ? (string) $row['updated_at'] : null
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'parent_id' => $this->parentId,
            'is_active' => $this->isActive ? 1 : 0,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
