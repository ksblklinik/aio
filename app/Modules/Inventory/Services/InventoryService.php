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

    public function checkAvailability(int $itemId, float $requiredQuantity, ?int $roomId = null): bool
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

        if ($roomId !== null) {
            return $this->getLocationStock($itemId, $roomId) >= $requiredQuantity;
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
        ?int $userId = null,
        ?int $roomId = null,
        ?int $batchId = null
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
            $userId,
            $roomId,
            $batchId
        ) {
            $item = $this->db->selectOne("SELECT * FROM items WHERE id = ? AND tenant_id = ?", [$itemId, $tenantId]);
            if (!$item) {
                throw new RuntimeException("Item tidak ditemukan.");
            }

            if ($item['type'] === 'service' || !(bool) $item['track_inventory']) {
                throw new RuntimeException("Item bertipe 'service' atau non-inventory tidak mengelola mutasi stok fisik.");
            }

            // SPEC-029B: Multi-warehouse room and batch validations
            if ($roomId !== null) {
                $room = $this->db->selectOne(
                    "SELECT id, name FROM facilities_rooms WHERE id = ? AND tenant_id = ?",
                    [$roomId, $tenantId]
                );
                if (!$room) {
                    throw new RuntimeException("Ruangan/gudang tidak valid atau tidak ditemukan.");
                }
            }

            $batch = null;
            if ($batchId !== null && $batchId > 0) {
                $batch = $this->db->selectOne(
                    "SELECT * FROM item_batches WHERE id = ? AND item_id = ? AND tenant_id = ?",
                    [$batchId, $itemId, $tenantId]
                );
                if (!$batch) {
                    throw new RuntimeException("Nomor batch tidak valid untuk item ini.");
                }
            }

            $batchKey = ($batchId !== null && $batchId > 0) ? $batchId : 0;

            // Location-specific balance check on 'out'
            if ($cleanType === 'out' && $roomId !== null) {
                $locRow = $this->db->selectOne(
                    "SELECT quantity FROM inventory_stock_locations WHERE tenant_id = ? AND item_id = ? AND room_id = ? AND batch_id = ?",
                    [$tenantId, $itemId, $roomId, $batchKey]
                );
                $roomStock = (float) ($locRow['quantity'] ?? 0.0);
                if ($roomStock < $quantity) {
                    throw new RuntimeException("Stok di ruangan ini tidak mencukupi. Sisa: {$roomStock}, diminta: {$quantity}.");
                }
            }

            // Batch-specific balance check on 'out'
            if ($cleanType === 'out' && $batch !== null) {
                $batchCurQty = (float) ($batch['current_quantity'] ?? 0.0);
                if ($batchCurQty < $quantity) {
                    throw new RuntimeException("Stok pada batch ini tidak mencukupi. Sisa: {$batchCurQty}, diminta: {$quantity}.");
                }
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
                'room_id' => $roomId,
                'batch_id' => $batchId,
            ]);

            if ($cleanType === 'out') {
                $affected = $this->db->affectingStatement(
                    "UPDATE items SET current_stock = round(current_stock - ?, 4), updated_at = ? 
                     WHERE id = ? AND tenant_id = ? AND current_stock >= ?",
                    [$quantity, $now, $itemId, $tenantId, $quantity]
                );
                if ($affected !== 1) {
                    throw new RuntimeException("Stok tidak mencukupi untuk pengeluaran ini.");
                }
            } elseif ($cleanType === 'in') {
                $this->db->statement(
                    "UPDATE items SET current_stock = round(current_stock + ?, 4), updated_at = ? WHERE id = ? AND tenant_id = ?",
                    [$quantity, $now, $itemId, $tenantId]
                );
            } elseif ($cleanType === 'opname') {
                $this->db->statement(
                    "UPDATE items SET current_stock = ?, updated_at = ? WHERE id = ? AND tenant_id = ?",
                    [$stockAfter, $now, $itemId, $tenantId]
                );
            } elseif ($cleanType === 'adjustment') {
                if ($quantity < 0.0) {
                    $absQty = abs($quantity);
                    $affected = $this->db->affectingStatement(
                        "UPDATE items SET current_stock = round(current_stock - ?, 4), updated_at = ? 
                         WHERE id = ? AND tenant_id = ? AND current_stock >= ?",
                        [$absQty, $now, $itemId, $tenantId, $absQty]
                    );
                    if ($affected !== 1) {
                        throw new RuntimeException("Penyesuaian stok mengakibatkan saldo negatif: {$stockAfter}.");
                    }
                } else {
                    $this->db->statement(
                        "UPDATE items SET current_stock = round(current_stock + ?, 4), updated_at = ? WHERE id = ? AND tenant_id = ?",
                        [$quantity, $now, $itemId, $tenantId]
                    );
                }
            }

            // Update location stock
            if ($roomId !== null) {
                $locRow = $this->db->selectOne(
                    "SELECT id, quantity FROM inventory_stock_locations WHERE tenant_id = ? AND item_id = ? AND room_id = ? AND batch_id = ?",
                    [$tenantId, $itemId, $roomId, $batchKey]
                );

                if ($cleanType === 'in') {
                    if (!$locRow) {
                        $this->db->insert('inventory_stock_locations', [
                            'tenant_id' => $tenantId,
                            'item_id' => $itemId,
                            'room_id' => $roomId,
                            'batch_id' => $batchKey,
                            'quantity' => $quantity,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    } else {
                        $newLocQty = round((float) $locRow['quantity'] + $quantity, 4);
                        $this->db->update('inventory_stock_locations', [
                            'quantity' => $newLocQty,
                            'updated_at' => $now,
                        ], 'id = ?', [(int) $locRow['id']]);
                    }
                } elseif ($cleanType === 'out') {
                    if (!$locRow) {
                        throw new RuntimeException("Stok di ruangan ini tidak mencukupi untuk pengeluaran ini.");
                    }
                    $affectedLoc = $this->db->affectingStatement(
                        "UPDATE inventory_stock_locations 
                         SET quantity = round(quantity - ?, 4), updated_at = ? 
                         WHERE id = ? AND quantity >= ?",
                        [$quantity, $now, (int) $locRow['id'], $quantity]
                    );
                    if ($affectedLoc !== 1) {
                        throw new RuntimeException("Stok di ruangan ini tidak mencukupi untuk pengeluaran ini.");
                    }
                } elseif ($cleanType === 'opname') {
                    if (!$locRow) {
                        $this->db->insert('inventory_stock_locations', [
                            'tenant_id' => $tenantId,
                            'item_id' => $itemId,
                            'room_id' => $roomId,
                            'batch_id' => $batchKey,
                            'quantity' => $quantity,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    } else {
                        $this->db->update('inventory_stock_locations', [
                            'quantity' => $quantity,
                            'updated_at' => $now,
                        ], 'id = ?', [(int) $locRow['id']]);
                    }
                } elseif ($cleanType === 'adjustment') {
                    if (!$locRow) {
                        $this->db->insert('inventory_stock_locations', [
                            'tenant_id' => $tenantId,
                            'item_id' => $itemId,
                            'room_id' => $roomId,
                            'batch_id' => $batchKey,
                            'quantity' => max(0.0, $quantity),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    } else {
                        $newLocQty = max(0.0, round((float) $locRow['quantity'] + $quantity, 4));
                        $this->db->update('inventory_stock_locations', [
                            'quantity' => $newLocQty,
                            'updated_at' => $now,
                        ], 'id = ?', [(int) $locRow['id']]);
                    }
                }
            }

            // Update batch current_quantity
            if ($batch !== null) {
                if ($cleanType === 'in') {
                    $this->db->statement(
                        "UPDATE item_batches SET current_quantity = round(current_quantity + ?, 4), updated_at = ? WHERE id = ?",
                        [$quantity, $now, $batchId]
                    );
                } elseif ($cleanType === 'out') {
                    $affectedBatch = $this->db->affectingStatement(
                        "UPDATE item_batches 
                         SET current_quantity = round(current_quantity - ?, 4), updated_at = ? 
                         WHERE id = ? AND current_quantity >= ?",
                        [$quantity, $now, $batchId, $quantity]
                    );
                    if ($affectedBatch !== 1) {
                        throw new RuntimeException("Stok pada batch ini tidak mencukupi untuk pengeluaran ini.");
                    }
                } elseif ($cleanType === 'opname') {
                    $this->db->statement(
                        "UPDATE item_batches SET current_quantity = ?, updated_at = ? WHERE id = ?",
                        [$quantity, $now, $batchId]
                    );
                } elseif ($cleanType === 'adjustment') {
                    $this->db->statement(
                        "UPDATE item_batches SET current_quantity = max(0.0, round(current_quantity + ?, 4)), updated_at = ? WHERE id = ?",
                        [$quantity, $now, $batchId]
                    );
                }
            }

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

    // ==========================================
    // SPEC-029B: Multi-Warehouse & Batch Methods
    // ==========================================

    public function getStockByLocation(int $itemId, ?int $roomId = null): array
    {
        $tenantId = $this->getTenantId();
        $sql = "SELECT sl.*,
                       r.name AS room_name,
                       r.code AS room_code,
                       r.floor AS room_floor,
                       l.name AS location_name,
                       b.batch_number,
                       b.expiry_date,
                       b.status AS batch_status
                FROM inventory_stock_locations sl
                JOIN facilities_rooms r ON r.id = sl.room_id
                JOIN facilities_locations l ON l.id = r.location_id
                LEFT JOIN item_batches b ON b.id = sl.batch_id
                WHERE sl.item_id = ? AND sl.tenant_id = ? AND sl.quantity > 0";
        $params = [$itemId, $tenantId];

        if ($roomId !== null) {
            $sql .= " AND sl.room_id = ?";
            $params[] = $roomId;
        }

        $sql .= " ORDER BY r.name ASC, b.expiry_date ASC";

        $rows = $this->db->selectAll($sql, $params);
        return array_map(function ($row) {
            $row['id'] = (int) $row['id'];
            $row['tenant_id'] = (int) $row['tenant_id'];
            $row['item_id'] = (int) $row['item_id'];
            $row['room_id'] = (int) $row['room_id'];
            $row['batch_id'] = (int) $row['batch_id'];
            $row['quantity'] = (float) $row['quantity'];
            return $row;
        }, $rows);
    }

    public function getLocationStock(int $itemId, int $roomId): float
    {
        $tenantId = $this->getTenantId();
        $row = $this->db->selectOne(
            "SELECT COALESCE(SUM(quantity), 0.0) AS total
             FROM inventory_stock_locations
             WHERE item_id = ? AND room_id = ? AND tenant_id = ?",
            [$itemId, $roomId, $tenantId]
        );
        return $row ? (float) ($row['total'] ?? 0.0) : 0.0;
    }

    public function createBatch(
        int $itemId,
        string $batchNumber,
        string $expiryDate,
        float $quantity = 0.0,
        float $costPrice = 0.0,
        ?string $notes = null
    ): int {
        $tenantId = $this->getTenantId();

        $item = $this->getItem($itemId);
        if (!$item) {
            throw new RuntimeException("Item dengan ID {$itemId} tidak ditemukan.");
        }

        $cleanBatch = trim($batchNumber);
        if ($cleanBatch === '') {
            throw new RuntimeException("Nomor batch tidak boleh kosong.");
        }

        $cleanExpiry = trim($expiryDate);
        if ($cleanExpiry === '') {
            throw new RuntimeException("Tanggal kadaluarsa batch wajib diisi.");
        }

        $effectiveCost = $costPrice > 0.0 ? $costPrice : (float) ($item['cost_price'] ?? 0.0);
        $now = date('Y-m-d H:i:s');

        $batchId = (int) $this->db->insert('item_batches', [
            'tenant_id' => $tenantId,
            'item_id' => $itemId,
            'batch_number' => $cleanBatch,
            'expiry_date' => $cleanExpiry,
            'initial_quantity' => $quantity,
            'current_quantity' => $quantity,
            'cost_price' => $effectiveCost,
            'status' => 'active',
            'notes' => $notes,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $batchId;
    }

    public function getBatch(int $batchId): ?array
    {
        $tenantId = $this->getTenantId();
        $row = $this->db->selectOne(
            "SELECT b.*, i.name AS item_name, i.code AS item_code
             FROM item_batches b
             JOIN items i ON i.id = b.item_id
             WHERE b.id = ? AND b.tenant_id = ?",
            [$batchId, $tenantId]
        );

        if (!$row) {
            return null;
        }

        $row['id'] = (int) $row['id'];
        $row['tenant_id'] = (int) $row['tenant_id'];
        $row['item_id'] = (int) $row['item_id'];
        $row['initial_quantity'] = (float) $row['initial_quantity'];
        $row['current_quantity'] = (float) $row['current_quantity'];
        $row['cost_price'] = (float) $row['cost_price'];

        return $row;
    }

    public function listBatches(int $itemId, ?string $status = 'active'): array
    {
        $tenantId = $this->getTenantId();
        $sql = "SELECT b.*, i.name AS item_name, i.code AS item_code
                FROM item_batches b
                JOIN items i ON i.id = b.item_id
                WHERE b.item_id = ? AND b.tenant_id = ?";
        $params = [$itemId, $tenantId];

        if ($status !== null && $status !== '') {
            $sql .= " AND b.status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY b.expiry_date ASC, b.id ASC";

        $rows = $this->db->selectAll($sql, $params);
        return array_map(function ($row) {
            $row['id'] = (int) $row['id'];
            $row['tenant_id'] = (int) $row['tenant_id'];
            $row['item_id'] = (int) $row['item_id'];
            $row['initial_quantity'] = (float) $row['initial_quantity'];
            $row['current_quantity'] = (float) $row['current_quantity'];
            $row['cost_price'] = (float) $row['cost_price'];
            return $row;
        }, $rows);
    }

    public function listNearExpiryItems(int $daysThreshold = 30): array
    {
        $tenantId = $this->getTenantId();
        $sql = "SELECT b.*,
                       i.name AS item_name,
                       i.code AS item_code,
                       i.type AS item_type,
                       u.code AS unit_code,
                       (CAST((julianday(b.expiry_date) - julianday('now')) AS INTEGER)) AS days_until_expiry
                FROM item_batches b
                JOIN items i ON i.id = b.item_id
                LEFT JOIN inventory_units u ON u.id = i.unit_id
                WHERE b.tenant_id = ?
                  AND b.status = 'active'
                  AND b.current_quantity > 0
                  AND date(b.expiry_date) <= date('now', '+' || ? || ' days')
                ORDER BY b.expiry_date ASC";

        $rows = $this->db->selectAll($sql, [$tenantId, $daysThreshold]);
        return array_map(function ($row) {
            $row['id'] = (int) $row['id'];
            $row['tenant_id'] = (int) $row['tenant_id'];
            $row['item_id'] = (int) $row['item_id'];
            $row['initial_quantity'] = (float) $row['initial_quantity'];
            $row['current_quantity'] = (float) $row['current_quantity'];
            $row['cost_price'] = (float) $row['cost_price'];
            $row['days_until_expiry'] = (int) ($row['days_until_expiry'] ?? 0);
            return $row;
        }, $rows);
    }

    public function updateBatchStatus(int $batchId, string $status): bool
    {
        $tenantId = $this->getTenantId();
        $cleanStatus = strtolower(trim($status));
        if (!in_array($cleanStatus, ['active', 'quarantined', 'expired'], true)) {
            throw new RuntimeException("Status batch tidak valid. Harus salah satu dari: active, quarantined, expired.");
        }

        $now = date('Y-m-d H:i:s');
        $affected = $this->db->update('item_batches', [
            'status' => $cleanStatus,
            'updated_at' => $now,
        ], 'id = ? AND tenant_id = ?', [$batchId, $tenantId]);

        return $affected > 0;
    }

    public function deductStockFefo(
        int $itemId,
        float $quantity,
        ?int $roomId = null,
        string $notes = '',
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?int $userId = null
    ): array {
        if ($quantity <= 0.0) {
            return [];
        }

        $tenantId = $this->getTenantId();

        $deductCallback = function () use ($itemId, $quantity, $roomId, $notes, $referenceType, $referenceId, $userId, $tenantId): array {
            return $this->db->transaction(function () use ($itemId, $quantity, $roomId, $notes, $referenceType, $referenceId, $userId, $tenantId) {
                $item = $this->getItem($itemId);
                if (!$item) {
                    throw new RuntimeException("Item dengan ID {$itemId} tidak ditemukan.");
                }

                if ($item['type'] === 'service' || !(bool) $item['track_inventory']) {
                    return [];
                }

                // Validate availability
                if ($roomId !== null) {
                    $locStock = $this->getLocationStock($itemId, $roomId);
                    if ($locStock < $quantity) {
                        throw new RuntimeException("Stok di ruangan ini tidak mencukupi untuk pemotongan FEFO. Sisa: {$locStock}, diminta: {$quantity}.");
                    }
                } else {
                    $totalStock = (float) $item['current_stock'];
                    if ($totalStock < $quantity) {
                        throw new RuntimeException("Total stok tidak mencukupi untuk pemotongan FEFO. Sisa: {$totalStock}, diminta: {$quantity}.");
                    }
                }

                // Query available batches ordered by FEFO (earliest expiry date first)
                if ($roomId !== null) {
                    $batches = $this->db->selectAll(
                        "SELECT b.id, b.batch_number, b.expiry_date, sl.quantity AS available_quantity
                         FROM inventory_stock_locations sl
                         JOIN item_batches b ON b.id = sl.batch_id
                         WHERE sl.item_id = ? AND sl.room_id = ? AND sl.tenant_id = ?
                           AND sl.quantity > 0 AND b.status = 'active'
                         ORDER BY b.expiry_date ASC, b.id ASC",
                        [$itemId, $roomId, $tenantId]
                    );
                } else {
                    $batches = $this->db->selectAll(
                        "SELECT b.id, b.batch_number, b.expiry_date, b.current_quantity AS available_quantity
                         FROM item_batches b
                         WHERE b.item_id = ? AND b.tenant_id = ?
                           AND b.current_quantity > 0 AND b.status = 'active'
                         ORDER BY b.expiry_date ASC, b.id ASC",
                        [$itemId, $tenantId]
                    );
                }

                $remaining = $quantity;
                $allocations = [];

                foreach ($batches as $b) {
                    if ($remaining <= 0.0) {
                        break;
                    }

                    $avail = (float) $b['available_quantity'];
                    $deductQty = min($remaining, $avail);

                    $this->recordStockMovement(
                        $itemId,
                        $deductQty,
                        'out',
                        $notes,
                        $referenceType,
                        $referenceId,
                        null,
                        $userId,
                        $roomId,
                        (int) $b['id']
                    );

                    $allocations[] = [
                        'batch_id' => (int) $b['id'],
                        'batch_number' => $b['batch_number'],
                        'expiry_date' => $b['expiry_date'],
                        'quantity' => $deductQty,
                    ];

                    $remaining = round($remaining - $deductQty, 4);
                }

                // If there is still remaining unbatched stock to deduct
                if ($remaining > 0.0) {
                    $this->recordStockMovement(
                        $itemId,
                        $remaining,
                        'out',
                        $notes,
                        $referenceType,
                        $referenceId,
                        null,
                        $userId,
                        $roomId,
                        null
                    );

                    $allocations[] = [
                        'batch_id' => null,
                        'batch_number' => null,
                        'expiry_date' => null,
                        'quantity' => $remaining,
                    ];
                }

                return $allocations;
            });
        };

        if (function_exists('lock')) {
            $lockManager = null;
            try {
                $lockManager = lock();
            } catch (\Throwable) {
                $lockManager = null;
            }

            if ($lockManager instanceof \Core\Lock\LockManagerInterface) {
                try {
                    return (array) $lockManager->run("inventory:stock:{$tenantId}:{$itemId}", $deductCallback, ttlSeconds: 5, waitTimeoutMs: 3000);
                } catch (\Core\Lock\LockTimeoutException $e) {
                    throw new RuntimeException("Stok item sedang diproses oleh transaksi lain. Silakan coba kembali dalam beberapa saat.", 0, $e);
                }
            }
        }

        return $deductCallback();
    }

    public function transferStock(
        int $sourceRoomId,
        int $destinationRoomId,
        array $items,
        ?string $notes = null,
        ?int $userId = null
    ): array {
        if ($sourceRoomId === $destinationRoomId) {
            throw new RuntimeException("Gudang asal dan gudang tujuan tidak boleh sama.");
        }

        if (empty($items)) {
            throw new RuntimeException("Daftar item yang akan ditransfer tidak boleh kosong.");
        }

        $tenantId = $this->getTenantId();

        // Validate source and destination rooms via facility registry
        $sourceRoom = (function_exists('facilities') && app()->has(\App\Modules\Facility\Contracts\FacilityRegistryInterface::class))
            ? facilities()->getRoom($sourceRoomId)
            : $this->db->selectOne("SELECT id, name FROM facilities_rooms WHERE id = ? AND tenant_id = ?", [$sourceRoomId, $tenantId]);
        if (!$sourceRoom) {
            throw new RuntimeException("Gudang asal tidak valid atau tidak ditemukan.");
        }

        $destRoom = (function_exists('facilities') && app()->has(\App\Modules\Facility\Contracts\FacilityRegistryInterface::class))
            ? facilities()->getRoom($destinationRoomId)
            : $this->db->selectOne("SELECT id, name FROM facilities_rooms WHERE id = ? AND tenant_id = ?", [$destinationRoomId, $tenantId]);
        if (!$destRoom) {
            throw new RuntimeException("Gudang tujuan tidak valid atau tidak ditemukan.");
        }

        return $this->db->transaction(function () use (
            $tenantId,
            $sourceRoomId,
            $destinationRoomId,
            $sourceRoom,
            $destRoom,
            $items,
            $notes,
            $userId
        ) {
            // Generate sequence
            $trfNumber = 'TRF/' . date('Y') . '/' . str_pad((string) mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
            if (function_exists('sequence')) {
                try {
                    $trfNumber = sequence('inventory_transfer', 'TRF', 5, 'yearly', null, $tenantId);
                } catch (\Throwable) {
                    // Fallback to random pattern
                }
            }

            $now = date('Y-m-d H:i:s');
            $transferDate = date('Y-m-d');

            $transferId = (int) $this->db->insert('inventory_transfers', [
                'tenant_id' => $tenantId,
                'transfer_number' => $trfNumber,
                'source_room_id' => $sourceRoomId,
                'destination_room_id' => $destinationRoomId,
                'status' => 'completed',
                'transfer_date' => $transferDate,
                'notes' => $notes,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($items as $it) {
                $itemId = (int) ($it['item_id'] ?? 0);
                $qty = (float) ($it['quantity'] ?? 0.0);
                $batchId = isset($it['batch_id']) ? (int) $it['batch_id'] : 0;

                if ($qty <= 0.0) {
                    continue;
                }

                $item = $this->getItem($itemId);
                if (!$item) {
                    throw new RuntimeException("Item ID {$itemId} tidak ditemukan.");
                }

                $batchKey = $batchId > 0 ? $batchId : 0;
                $sourceStockRow = $this->db->selectOne(
                    "SELECT quantity FROM inventory_stock_locations WHERE tenant_id = ? AND item_id = ? AND room_id = ? AND batch_id = ?",
                    [$tenantId, $itemId, $sourceRoomId, $batchKey]
                );
                $avail = (float) ($sourceStockRow['quantity'] ?? 0.0);

                if ($avail < $qty) {
                    throw new RuntimeException("Stok di gudang asal tidak mencukupi untuk item {$item['name']} (Sisa: {$avail}, diminta: {$qty}).");
                }

                // Transfer out from source room
                $this->recordStockMovement(
                    $itemId,
                    $qty,
                    'out',
                    "Transfer keluar ke {$destRoom['name']} ({$trfNumber})",
                    'inventory_transfer',
                    (string) $transferId,
                    null,
                    $userId,
                    $sourceRoomId,
                    $batchId > 0 ? $batchId : null
                );

                // Transfer in to destination room
                $this->recordStockMovement(
                    $itemId,
                    $qty,
                    'in',
                    "Transfer masuk dari {$sourceRoom['name']} ({$trfNumber})",
                    'inventory_transfer',
                    (string) $transferId,
                    null,
                    $userId,
                    $destinationRoomId,
                    $batchId > 0 ? $batchId : null
                );

                $this->db->insert('inventory_transfer_items', [
                    'transfer_id' => $transferId,
                    'item_id' => $itemId,
                    'batch_id' => $batchKey,
                    'quantity' => $qty,
                    'notes' => $it['notes'] ?? null,
                    'created_at' => $now,
                ]);
            }

            return $this->getTransfer($transferId) ?? throw new RuntimeException('Gagal memuat data transfer.');
        });
    }

    public function getTransfer(int $transferId): ?array
    {
        $tenantId = $this->getTenantId();
        $row = $this->db->selectOne(
            "SELECT t.*,
                    sr.name AS source_room_name,
                    dr.name AS destination_room_name
             FROM inventory_transfers t
             JOIN facilities_rooms sr ON sr.id = t.source_room_id
             JOIN facilities_rooms dr ON dr.id = t.destination_room_id
             WHERE t.id = ? AND t.tenant_id = ?",
            [$transferId, $tenantId]
        );

        if (!$row) {
            return null;
        }

        $row['id'] = (int) $row['id'];
        $row['tenant_id'] = (int) $row['tenant_id'];
        $row['source_room_id'] = (int) $row['source_room_id'];
        $row['destination_room_id'] = (int) $row['destination_room_id'];

        $items = $this->db->selectAll(
            "SELECT ti.*, i.name AS item_name, i.code AS item_code, b.batch_number, b.expiry_date
             FROM inventory_transfer_items ti
             JOIN items i ON i.id = ti.item_id
             LEFT JOIN item_batches b ON b.id = ti.batch_id
             WHERE ti.transfer_id = ?",
            [$transferId]
        );

        $row['items'] = array_map(function ($it) {
            $it['id'] = (int) $it['id'];
            $it['transfer_id'] = (int) $it['transfer_id'];
            $it['item_id'] = (int) $it['item_id'];
            $it['batch_id'] = (int) $it['batch_id'];
            $it['quantity'] = (float) $it['quantity'];
            return $it;
        }, $items);

        return $row;
    }

    public function listTransfers(array $filters = []): array
    {
        $tenantId = $this->getTenantId();
        $sql = "SELECT t.*,
                       sr.name AS source_room_name,
                       dr.name AS destination_room_name,
                       (SELECT COUNT(*) FROM inventory_transfer_items ti WHERE ti.transfer_id = t.id) AS items_count
                FROM inventory_transfers t
                JOIN facilities_rooms sr ON sr.id = t.source_room_id
                JOIN facilities_rooms dr ON dr.id = t.destination_room_id
                WHERE t.tenant_id = ?";
        $params = [$tenantId];

        if (!empty($filters['source_room_id'])) {
            $sql .= " AND t.source_room_id = ?";
            $params[] = (int) $filters['source_room_id'];
        }

        if (!empty($filters['destination_room_id'])) {
            $sql .= " AND t.destination_room_id = ?";
            $params[] = (int) $filters['destination_room_id'];
        }

        if (!empty($filters['status'])) {
            $sql .= " AND t.status = ?";
            $params[] = (string) $filters['status'];
        }

        $sql .= " ORDER BY t.created_at DESC, t.id DESC";

        $rows = $this->db->selectAll($sql, $params);
        return array_map(function ($row) {
            $row['id'] = (int) $row['id'];
            $row['tenant_id'] = (int) $row['tenant_id'];
            $row['source_room_id'] = (int) $row['source_room_id'];
            $row['destination_room_id'] = (int) $row['destination_room_id'];
            $row['items_count'] = (int) ($row['items_count'] ?? 0);
            return $row;
        }, $rows);
    }
}

