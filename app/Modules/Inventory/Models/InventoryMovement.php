<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

class InventoryMovement
{
    public function __construct(
        public ?int $id = null,
        public int $tenantId = 0,
        public string $movementNumber = '',
        public int $itemId = 0,
        public string $type = 'in', // 'in', 'out', 'adjustment', 'opname'
        public float $quantity = 0.0,
        public float $stockBefore = 0.0,
        public float $stockAfter = 0.0,
        public ?float $unitCost = null,
        public ?string $referenceType = null,
        public ?string $referenceId = null,
        public ?string $notes = null,
        public ?int $createdBy = null,
        public ?string $createdAt = null
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
            movementNumber: (string) ($row['movement_number'] ?? ''),
            itemId: (int) ($row['item_id'] ?? 0),
            type: (string) ($row['type'] ?? 'in'),
            quantity: (float) ($row['quantity'] ?? 0.0),
            stockBefore: (float) ($row['stock_before'] ?? 0.0),
            stockAfter: (float) ($row['stock_after'] ?? 0.0),
            unitCost: isset($row['unit_cost']) && $row['unit_cost'] !== null ? (float) $row['unit_cost'] : null,
            referenceType: isset($row['reference_type']) ? (string) $row['reference_type'] : null,
            referenceId: isset($row['reference_id']) ? (string) $row['reference_id'] : null,
            notes: isset($row['notes']) ? (string) $row['notes'] : null,
            createdBy: isset($row['created_by']) && $row['created_by'] !== null ? (int) $row['created_by'] : null,
            createdAt: isset($row['created_at']) ? (string) $row['created_at'] : null
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
            'movement_number' => $this->movementNumber,
            'item_id' => $this->itemId,
            'type' => $this->type,
            'quantity' => $this->quantity,
            'stock_before' => $this->stockBefore,
            'stock_after' => $this->stockAfter,
            'unit_cost' => $this->unitCost,
            'reference_type' => $this->referenceType,
            'reference_id' => $this->referenceId,
            'notes' => $this->notes,
            'created_by' => $this->createdBy,
            'created_at' => $this->createdAt,
        ];
    }
}
