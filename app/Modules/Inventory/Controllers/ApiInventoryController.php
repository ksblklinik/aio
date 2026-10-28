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
        $roomId = $request->query('room_id') ? (int) $request->query('room_id') : null;
        $available = $this->inventoryService->checkAvailability($id, $qty, $roomId);
        $currentStock = $roomId !== null
            ? $this->inventoryService->getStockByLocation($id, $roomId)
            : $this->inventoryService->getStock($id);

        return Response::json([
            'status' => 'success',
            'data' => [
                'item_id' => $id,
                'room_id' => $roomId,
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
                unitCost: $request->input('unit_cost') !== null ? (float) $request->input('unit_cost') : null,
                userId: null,
                roomId: $request->input('room_id') ? (int) $request->input('room_id') : null,
                batchId: $request->input('batch_id') ? (int) $request->input('batch_id') : null
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

    public function locationStock(Request $request, int $id): Response
    {
        $roomId = $request->query('room_id') ? (int) $request->query('room_id') : null;
        return Response::json([
            'status' => 'success',
            'data' => $this->inventoryService->getStockByLocation($id, $roomId),
        ]);
    }

    public function batches(Request $request, int $id): Response
    {
        $status = $request->query('status') !== null ? (string) $request->query('status') : 'active';
        if ($status === 'all') {
            $status = null;
        }

        return Response::json([
            'status' => 'success',
            'data' => $this->inventoryService->listBatches($id, $status),
        ]);
    }

    public function storeBatch(Request $request, int $id): Response
    {
        try {
            $batchId = $this->inventoryService->createBatch(
                itemId: $id,
                batchNumber: (string) $request->input('batch_number'),
                expiryDate: (string) $request->input('expiry_date'),
                quantity: (float) $request->input('quantity', 0.0),
                costPrice: (float) $request->input('cost_price', 0.0),
                notes: $request->input('notes') ? (string) $request->input('notes') : null
            );

            return Response::json([
                'status' => 'success',
                'message' => 'Batch created successfully.',
                'data' => $this->inventoryService->getBatch($batchId),
            ], 201);
        } catch (Throwable $e) {
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function nearExpiry(Request $request): Response
    {
        $days = (int) $request->query('days', 30);
        return Response::json([
            'status' => 'success',
            'data' => $this->inventoryService->listNearExpiryItems($days),
        ]);
    }

    public function transfers(Request $request): Response
    {
        $filters = [];
        if ($request->query('item_id')) {
            $filters['item_id'] = (int) $request->query('item_id');
        }
        if ($request->query('source_room_id')) {
            $filters['source_room_id'] = (int) $request->query('source_room_id');
        }
        if ($request->query('destination_room_id')) {
            $filters['destination_room_id'] = (int) $request->query('destination_room_id');
        }

        return Response::json([
            'status' => 'success',
            'data' => $this->inventoryService->listTransfers($filters),
        ]);
    }

    public function showTransfer(int $id): Response
    {
        $transfer = $this->inventoryService->getTransfer($id);
        if (!$transfer) {
            return Response::json(['status' => 'error', 'message' => 'Transfer not found.'], 404);
        }

        return Response::json([
            'status' => 'success',
            'data' => $transfer,
        ]);
    }

    public function storeTransfer(Request $request): Response
    {
        try {
            $sourceRoomId = (int) $request->input('source_room_id');
            $destinationRoomId = (int) $request->input('destination_room_id');
            $notes = $request->input('notes') ? (string) $request->input('notes') : null;

            $items = $request->input('items');
            if (!is_array($items)) {
                $items = [[
                    'item_id' => (int) $request->input('item_id'),
                    'quantity' => (float) $request->input('quantity'),
                    'batch_id' => $request->input('batch_id') ? (int) $request->input('batch_id') : null,
                ]];
            }

            $transfer = $this->inventoryService->transferStock(
                sourceRoomId: $sourceRoomId,
                destinationRoomId: $destinationRoomId,
                items: $items,
                notes: $notes
            );

            return Response::json([
                'status' => 'success',
                'message' => 'Stock transferred successfully.',
                'data' => $transfer,
            ], 201);
        } catch (Throwable $e) {
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }
}
