<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Contracts\InventoryRegistryInterface;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryUnit;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemCategory;
use Core\Database\Connection;
use Core\Database\TenantContext;
use RuntimeException;

class InventoryService implements InventoryRegistryInterface
{
    public const DEFAULT_UNITS = [
        ['code' => 'PCS', 'name' => 'Pieces / Buah'],
        ['code' => 'BOX', 'name' => 'Kotak / Dus'],
        ['code' => 'STRIP', 'name' => 'Strip'],
        ['code' => 'BTL', 'name' => 'Botol'],
        ['code' => 'KG', 'name' => 'Kilogram'],
        ['code' => 'LTR', 'name' => 'Liter'],
        ['code' => 'JAM', 'name' => 'Jam (Layanan)'],
        ['code' => 'SESI', 'name' => 'Sesi (Layanan)'],
        ['code' => 'PAKET', 'name' => 'Paket'],
    ];

    public function __construct(
        private Connection $db,
        private TenantContext $tenantContext
    ) {
    }

    private function getTenantId(): int
    {
        if (!$this->tenantContext->hasTenant()) {
            throw new RuntimeException("TenantContext is required to operate on inventory.");
        }
        return (int) $this->tenantContext->getTenantId();
    }

    public function createItem(array $data): int
    {
        $tenantId = $this->getTenantId();
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException("Item name is required.");
        }

        $type = (string) ($data['type'] ?? 'goods');
        $trackInventory = isset($data['track_inventory'])
            ? (bool) $data['track_inventory']
            : ($type !== 'service');

        // Generate automatic code if not supplied
        $code = trim((string) ($data['code'] ?? ''));
        if ($code === '') {
            $code = $this->generateItemCode();
        }

        $initialStock = $trackInventory ? (float) ($data['initial_stock'] ?? $data['current_stock'] ?? 0.0) : 0.0;
        $now = date('Y-m-d H:i:s');

        return $this->db->transaction(function () use (
            $tenantId,
            $code,
            $data,
            $name,
            $type,
            $trackInventory,
            $initialStock,
            $now
        ) {
            $itemId = (int) $this->db->insert('items', [
                'tenant_id' => $tenantId,
                'code' => $code,
                'sku' => !empty($data['sku']) ? trim((string) $data['sku']) : null,
                'barcode' => !empty($data['barcode']) ? trim((string) $data['barcode']) : null,
                'name' => $name,
                'type' => $type,
                'category_id' => !empty($data['category_id']) ? (int) $data['category_id'] : null,
                'unit_id' => !empty($data['unit_id']) ? (int) $data['unit_id'] : null,
                'cost_price' => (float) ($data['cost_price'] ?? 0.0),
                'selling_price' => (float) ($data['selling_price'] ?? 0.0),
                'current_stock' => $initialStock,
                'min_stock_alert' => (float) ($data['min_stock_alert'] ?? 0.0),
                'track_inventory' => $trackInventory ? 1 : 0,
                'is_active' => isset($data['is_active']) ? (int) $data['is_active'] : 1,
                'description' => $data['description'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($trackInventory && $initialStock > 0.0) {
                $movNumber = $this->generateMovementNumber();
                $this->db->insert('inventory_movements', [
                    'tenant_id' => $tenantId,
                    'movement_number' => $movNumber,
                    'item_id' => $itemId,
                    'type' => 'in',
                    'quantity' => $initialStock,
                    'stock_before' => 0.0,
                    'stock_after' => $initialStock,
                    'unit_cost' => (float) ($data['cost_price'] ?? 0.0),
                    'reference_type' => 'initial_stock',
                    'reference_id' => (string) $itemId,
                    'notes' => 'Saldo Awal Item',
                    'created_by' => isset($data['created_by']) ? (int) $data['created_by'] : null,
                    'created_at' => $now,
                ]);
            }

            return $itemId;
        });
    }

    public function updateItem(int $itemId, array $data): bool
    {
        $tenantId = $this->getTenantId();
        $item = $this->db->selectOne("SELECT * FROM items WHERE id = ? AND tenant_id = ?", [$itemId, $tenantId]);
        if (!$item) {
            throw new RuntimeException("Item not found.");
        }

        $fields = [];
        $params = [];

        if (isset($data['name'])) {
            $fields[] = 'name = ?';
            $params[] = trim((string) $data['name']);
        }
        if (array_key_exists('sku', $data)) {
            $fields[] = 'sku = ?';
            $params[] = !empty($data['sku']) ? trim((string) $data['sku']) : null;
        }
        if (array_key_exists('barcode', $data)) {
            $fields[] = 'barcode = ?';
            $params[] = !empty($data['barcode']) ? trim((string) $data['barcode']) : null;
        }
        if (isset($data['type'])) {
            $fields[] = 'type = ?';
            $params[] = (string) $data['type'];
        }
        if (array_key_exists('category_id', $data)) {
            $fields[] = 'category_id = ?';
            $params[] = !empty($data['category_id']) ? (int) $data['category_id'] : null;
        }
        if (array_key_exists('unit_id', $data)) {
            $fields[] = 'unit_id = ?';
            $params[] = !empty($data['unit_id']) ? (int) $data['unit_id'] : null;
        }
        if (isset($data['cost_price'])) {
            $fields[] = 'cost_price = ?';
            $params[] = (float) $data['cost_price'];
        }
        if (isset($data['selling_price'])) {
            $fields[] = 'selling_price = ?';
            $params[] = (float) $data['selling_price'];
        }
        if (isset($data['min_stock_alert'])) {
            $fields[] = 'min_stock_alert = ?';
            $params[] = (float) $data['min_stock_alert'];
        }
        if (isset($data['track_inventory'])) {
            $fields[] = 'track_inventory = ?';
            $params[] = $data['track_inventory'] ? 1 : 0;
        }
        if (isset($data['is_active'])) {
            $fields[] = 'is_active = ?';
            $params[] = $data['is_active'] ? 1 : 0;
        }
        if (array_key_exists('description', $data)) {
            $fields[] = 'description = ?';
            $params[] = $data['description'];
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = 'updated_at = ?';
        $params[] = date('Y-m-d H:i:s');
        $params[] = $itemId;
        $params[] = $tenantId;

        $sql = "UPDATE items SET " . implode(', ', $fields) . " WHERE id = ? AND tenant_id = ?";
        return $this->db->statement($sql, $params);
    }

    public function getItem(int $itemId): ?array
    {
        $tenantId = $this->getTenantId();
        $sql = "SELECT i.*, u.code as unit_code, u.name as unit_name, c.name as category_name
                FROM items i
                LEFT JOIN inventory_units u ON i.unit_id = u.id
                LEFT JOIN item_categories c ON i.category_id = c.id
                WHERE i.id = ? AND i.tenant_id = ?";

        $row = $this->db->selectOne($sql, [$itemId, $tenantId]);
        return $row ? Item::fromArray($row)->toArray() : null;
    }

    public function getItemByCode(string $code): ?array
    {
        $tenantId = $this->getTenantId();
        $sql = "SELECT i.*, u.code as unit_code, u.name as unit_name, c.name as category_name
                FROM items i
                LEFT JOIN inventory_units u ON i.unit_id = u.id
                LEFT JOIN item_categories c ON i.category_id = c.id
                WHERE i.code = ? AND i.tenant_id = ?";

        $row = $this->db->selectOne($sql, [$code, $tenantId]);
        return $row ? Item::fromArray($row)->toArray() : null;
    }

    public function getItemByBarcode(string $barcode): ?array
    {
        $tenantId = $this->getTenantId();
        $sql = "SELECT i.*, u.code as unit_code, u.name as unit_name, c.name as category_name
                FROM items i
                LEFT JOIN inventory_units u ON i.unit_id = u.id
                LEFT JOIN item_categories c ON i.category_id = c.id
                WHERE (i.barcode = ? OR i.sku = ?) AND i.tenant_id = ?";

        $row = $this->db->selectOne($sql, [$barcode, $barcode, $tenantId]);
        return $row ? Item::fromArray($row)->toArray() : null;
    }

    public function searchItems(string $query = '', ?string $type = null, int $limit = 50): array
    {
        $tenantId = $this->getTenantId();
        $sql = "SELECT i.*, u.code as unit_code, u.name as unit_name, c.name as category_name
                FROM items i
                LEFT JOIN inventory_units u ON i.unit_id = u.id
                LEFT JOIN item_categories c ON i.category_id = c.id
                WHERE i.tenant_id = ? AND i.is_active = 1";
        $params = [$tenantId];

        if ($type !== null && $type !== '') {
            $sql .= " AND i.type = ?";
            $params[] = $type;
        }

        $cleanQuery = trim($query);
        if ($cleanQuery !== '') {
            $sql .= " AND (i.name LIKE ? OR i.code LIKE ? OR i.sku LIKE ? OR i.barcode LIKE ?)";
            $term = "%{$cleanQuery}%";
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        $sql .= " ORDER BY i.name ASC LIMIT ?";
        $params[] = max(1, $limit);

        $rows = $this->db->selectAll($sql, $params);
        return array_map(fn($r) => Item::fromArray($r)->toArray(), $rows);
    }

    public function getStock(int $itemId): float
    {
        $tenantId = $this->getTenantId();
        $row = $this->db->selectOne("SELECT current_stock, track_inventory FROM items WHERE id = ? AND tenant_id = ?", [$itemId, $tenantId]);
        if (!$row) {
            return 0.0;
        }
        return (float) $row['current_stock'];
    }

    public function checkAvailability(int $itemId, float $requiredQuantity): bool
    {
        if ($requiredQuantity <= 0.0) {
            return true;
        }

        $tenantId = $this->getTenantId();
        $row = $this->db->selectOne("SELECT type, current_stock, track_inventory FROM items WHERE id = ? AND tenant_id = ?", [$itemId, $tenantId]);
        if (!$row) {
            return false;
        }

        // Layanan atau non-tracked item selalu tersedia
        if ($row['type'] === 'service' || !(bool) $row['track_inventory']) {
            return true;
        }

        return (float) $row['current_stock'] >= $requiredQuantity;
    }

    public function recordStockMovement(
        int $itemId,
        float $quantity,
        string $type,
        string $notes = '',
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?float $unitCost = null,
        ?int $userId = null
    ): int {
        $tenantId = $this->getTenantId();
        $cleanType = strtolower(trim($type));

        if (!in_array($cleanType, ['in', 'out', 'adjustment', 'opname'], true)) {
            throw new RuntimeException("Tipe mutasi tidak valid. Harus salah satu dari: in, out, adjustment, opname.");
        }

        return $this->db->transaction(function () use (
            $tenantId,
            $itemId,
            $quantity,
            $cleanType,
            $notes,
            $referenceType,
            $referenceId,
            $unitCost,
            $userId
        ) {
            $item = $this->db->selectOne("SELECT * FROM items WHERE id = ? AND tenant_id = ?", [$itemId, $tenantId]);
            if (!$item) {
                throw new RuntimeException("Item tidak ditemukan.");
            }

            if ($item['type'] === 'service' || !(bool) $item['track_inventory']) {
                throw new RuntimeException("Item bertipe 'service' atau non-inventory tidak mengelola mutasi stok fisik.");
            }

            $stockBefore = (float) $item['current_stock'];
            $stockAfter = $stockBefore;
            $mutationQty = $quantity;

            if ($cleanType === 'in') {
                if ($quantity <= 0.0) {
                    throw new RuntimeException("Kuantitas barang masuk harus lebih besar dari nol.");
                }
                $stockAfter = round($stockBefore + $quantity, 4);
            } elseif ($cleanType === 'out') {
                if ($quantity <= 0.0) {
                    throw new RuntimeException("Kuantitas barang keluar harus lebih besar dari nol.");
                }
                if ($stockBefore < $quantity) {
                    throw new RuntimeException("Stok tidak mencukupi untuk pengeluaran ini. Sisa: {$stockBefore}, diminta: {$quantity}.");
                }
                $stockAfter = round($stockBefore - $quantity, 4);
            } elseif ($cleanType === 'opname') {
                // Opname mendefinisikan stok fisik sebenarnya
                if ($quantity < 0.0) {
                    throw new RuntimeException("Hasil stok opname fisik tidak boleh negatif.");
                }
                $stockAfter = round($quantity, 4);
                $mutationQty = round($stockAfter - $stockBefore, 4);
            } elseif ($cleanType === 'adjustment') {
                // Adjustment: quantity adalah perubahan (+ atau -)
                $stockAfter = round($stockBefore + $quantity, 4);
                if ($stockAfter < 0.0) {
                    throw new RuntimeException("Penyesuaian stok mengakibatkan saldo negatif: {$stockAfter}.");
                }
            }

            $now = date('Y-m-d H:i:s');
            $movNumber = $this->generateMovementNumber();

            $movId = (int) $this->db->insert('inventory_movements', [
                'tenant_id' => $tenantId,
                'movement_number' => $movNumber,
                'item_id' => $itemId,
                'type' => $cleanType,
                'quantity' => $mutationQty,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'unit_cost' => $unitCost !== null ? $unitCost : (float) $item['cost_price'],
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'notes' => $notes,
                'created_by' => $userId,
                'created_at' => $now,
            ]);

            $this->db->statement(
                "UPDATE items SET current_stock = ?, updated_at = ? WHERE id = ? AND tenant_id = ?",
                [$stockAfter, $now, $itemId, $tenantId]
            );

            return $movId;
        });
    }

    public function listUnits(): array
    {
        $tenantId = $this->getTenantId();
        $this->seedDefaultUnitsIfEmpty($tenantId);

        $rows = $this->db->selectAll(
            "SELECT * FROM inventory_units WHERE tenant_id = ? AND is_active = 1 ORDER BY id ASC",
            [$tenantId]
        );

        return array_map(fn($r) => InventoryUnit::fromArray($r)->toArray(), $rows);
    }

    public function createUnit(string $code, string $name): int
    {
        $tenantId = $this->getTenantId();
        $cleanCode = strtoupper(trim($code));
        $cleanName = trim($name);

        if ($cleanCode === '' || $cleanName === '') {
            throw new RuntimeException("Unit code and name are required.");
        }

        $now = date('Y-m-d H:i:s');
        return (int) $this->db->insert('inventory_units', [
            'tenant_id' => $tenantId,
            'code' => $cleanCode,
            'name' => $cleanName,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function listCategories(): array
    {
        $tenantId = $this->getTenantId();
        $rows = $this->db->selectAll(
            "SELECT * FROM item_categories WHERE tenant_id = ? AND is_active = 1 ORDER BY name ASC",
            [$tenantId]
        );

        return array_map(fn($r) => ItemCategory::fromArray($r)->toArray(), $rows);
    }

    public function createCategory(string $code, string $name, ?int $parentId = null): int
    {
        $tenantId = $this->getTenantId();
        $cleanCode = strtoupper(trim($code));
        $cleanName = trim($name);

        if ($cleanCode === '' || $cleanName === '') {
            throw new RuntimeException("Category code and name are required.");
        }

        $now = date('Y-m-d H:i:s');
        return (int) $this->db->insert('item_categories', [
            'tenant_id' => $tenantId,
            'code' => $cleanCode,
            'name' => $cleanName,
            'parent_id' => $parentId,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function listLowStockItems(): array
    {
        $tenantId = $this->getTenantId();
        $sql = "SELECT i.*, u.code as unit_code, u.name as unit_name, c.name as category_name
                FROM items i
                LEFT JOIN inventory_units u ON i.unit_id = u.id
                LEFT JOIN item_categories c ON i.category_id = c.id
                WHERE i.tenant_id = ? AND i.track_inventory = 1 AND i.is_active = 1
                  AND i.min_stock_alert > 0 AND i.current_stock <= i.min_stock_alert
                ORDER BY i.current_stock ASC";

        $rows = $this->db->selectAll($sql, [$tenantId]);
        return array_map(fn($r) => Item::fromArray($r)->toArray(), $rows);
    }

    public function getStockMovementHistory(int $itemId, int $limit = 50): array
    {
        $tenantId = $this->getTenantId();
        $sql = "SELECT * FROM inventory_movements
                WHERE item_id = ? AND tenant_id = ?
                ORDER BY created_at DESC, id DESC LIMIT ?";

        $rows = $this->db->selectAll($sql, [$itemId, $tenantId, max(1, $limit)]);
        return array_map(fn($r) => InventoryMovement::fromArray($r)->toArray(), $rows);
    }

    private function seedDefaultUnitsIfEmpty(int $tenantId): void
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) as cnt FROM inventory_units WHERE tenant_id = ?",
            [$tenantId]
        );
        $count = $row ? (int) ($row['cnt'] ?? 0) : 0;

        if ($count === 0) {
            $now = date('Y-m-d H:i:s');
            foreach (self::DEFAULT_UNITS as $u) {
                $this->db->insert('inventory_units', [
                    'tenant_id' => $tenantId,
                    'code' => $u['code'],
                    'name' => $u['name'],
                    'is_active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function generateItemCode(): string
    {
        if (function_exists('sequence')) {
            try {
                return sequence('inventory', 'ITM', 5, 'yearly');
            } catch (\Throwable) {
                // fallback
            }
        }
        return 'ITM/' . date('Y') . '/' . str_pad((string) mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
    }

    private function generateMovementNumber(): string
    {
        if (function_exists('sequence')) {
            try {
                return sequence('inventory_movement', 'MOV', 5, 'yearly');
            } catch (\Throwable) {
                // fallback
            }
        }
        return 'MOV/' . date('Y') . '/' . str_pad((string) mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
    }
}
