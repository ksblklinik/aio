<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Modules\Inventory\Contracts\InventoryRegistryInterface;
use Core\Http\Request;
use Core\Http\Response;
use Throwable;

class ApiInventoryController
{
    public function __construct(
        private InventoryRegistryInterface $inventoryService
    ) {
    }

    public function search(Request $request): Response
    {
        $query = (string) $request->query('q', '');
        $type = $request->query('type') ? (string) $request->query('type') : null;
        $limit = (int) $request->query('limit', 50);

        return Response::json([
            'status' => 'success',
            'data' => $this->inventoryService->searchItems($query, $type, $limit),
        ]);
    }

    public function show(int $id): Response
    {
        $item = $this->inventoryService->getItem($id);
        if (!$item) {
            return Response::json(['status' => 'error', 'message' => 'Item not found.'], 404);
        }

        return Response::json([
            'status' => 'success',
            'data' => $item,
        ]);
    }

    public function lookup(Request $request): Response
    {
        $code = (string) $request->query('code', '');
        $barcode = (string) $request->query('barcode', '');

        $item = null;
        if ($code !== '') {
            $item = $this->inventoryService->getItemByCode($code);
        } elseif ($barcode !== '') {
            $item = $this->inventoryService->getItemByBarcode($barcode);
        }

        if (!$item) {
            return Response::json(['status' => 'error', 'message' => 'Item not found.'], 404);
        }

        return Response::json([
            'status' => 'success',
            'data' => $item,
        ]);
    }

    public function checkStock(Request $request, int $id): Response
    {
        $qty = (float) $request->query('qty', 1.0);
        $available = $this->inventoryService->checkAvailability($id, $qty);
        $currentStock = $this->inventoryService->getStock($id);

        return Response::json([
            'status' => 'success',
            'data' => [
                'item_id' => $id,
                'required_quantity' => $qty,
                'is_available' => $available,
                'current_stock' => $currentStock,
            ],
        ]);
    }

    public function storeMovement(Request $request, int $id): Response
    {
        try {
            $movId = $this->inventoryService->recordStockMovement(
                itemId: $id,
                quantity: (float) $request->input('quantity'),
                type: (string) $request->input('type', 'adjustment'),
                notes: (string) $request->input('notes', ''),
                referenceType: $request->input('reference_type') ? (string) $request->input('reference_type') : null,
                referenceId: $request->input('reference_id') ? (string) $request->input('reference_id') : null,
                unitCost: $request->input('unit_cost') !== null ? (float) $request->input('unit_cost') : null
            );

            return Response::json([
                'status' => 'success',
                'message' => 'Stock movement recorded successfully.',
                'data' => ['movement_id' => $movId],
            ], 201);
        } catch (Throwable $e) {
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function lowStock(): Response
    {
        return Response::json([
            'status' => 'success',
            'data' => $this->inventoryService->listLowStockItems(),
        ]);
    }
}
