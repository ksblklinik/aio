<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

class Item
{
    public function __construct(
        public ?int $id = null,
        public int $tenantId = 0,
        public string $code = '',
        public ?string $sku = null,
        public ?string $barcode = null,
        public string $name = '',
        public string $type = 'goods', // 'goods', 'service', 'raw_material', 'bundle'
        public ?int $categoryId = null,
        public ?int $unitId = null,
        public float $costPrice = 0.0,
        public float $sellingPrice = 0.0,
        public float $currentStock = 0.0,
        public float $minStockAlert = 0.0,
        public bool $trackInventory = true,
        public bool $isActive = true,
        public ?string $description = null,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
        public ?string $unitCode = null,
        public ?string $unitName = null,
        public ?string $categoryName = null
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
            sku: isset($row['sku']) && $row['sku'] !== '' ? (string) $row['sku'] : null,
            barcode: isset($row['barcode']) && $row['barcode'] !== '' ? (string) $row['barcode'] : null,
            name: (string) ($row['name'] ?? ''),
            type: (string) ($row['type'] ?? 'goods'),
            categoryId: isset($row['category_id']) && $row['category_id'] !== null ? (int) $row['category_id'] : null,
            unitId: isset($row['unit_id']) && $row['unit_id'] !== null ? (int) $row['unit_id'] : null,
            costPrice: (float) ($row['cost_price'] ?? 0.0),
            sellingPrice: (float) ($row['selling_price'] ?? 0.0),
            currentStock: (float) ($row['current_stock'] ?? 0.0),
            minStockAlert: (float) ($row['min_stock_alert'] ?? 0.0),
            trackInventory: isset($row['track_inventory']) ? (bool) $row['track_inventory'] : true,
            isActive: (bool) ($row['is_active'] ?? true),
            description: isset($row['description']) ? (string) $row['description'] : null,
            createdAt: isset($row['created_at']) ? (string) $row['created_at'] : null,
            updatedAt: isset($row['updated_at']) ? (string) $row['updated_at'] : null,
            unitCode: isset($row['unit_code']) ? (string) $row['unit_code'] : null,
            unitName: isset($row['unit_name']) ? (string) $row['unit_name'] : null,
            categoryName: isset($row['category_name']) ? (string) $row['category_name'] : null
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
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'name' => $this->name,
            'type' => $this->type,
            'category_id' => $this->categoryId,
            'unit_id' => $this->unitId,
            'cost_price' => $this->costPrice,
            'selling_price' => $this->sellingPrice,
            'current_stock' => $this->currentStock,
            'min_stock_alert' => $this->minStockAlert,
            'track_inventory' => $this->trackInventory ? 1 : 0,
            'is_active' => $this->isActive ? 1 : 0,
            'description' => $this->description,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'unit_code' => $this->unitCode,
            'unit_name' => $this->unitName,
            'category_name' => $this->categoryName,
        ];
    }
}
