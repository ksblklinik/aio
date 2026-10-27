<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Inventory\Contracts\InventoryRegistryInterface;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;
use Core\Inertia\Inertia;
use Throwable;

class InventoryController
{
    public function __construct(
        private AuthService $auth,
        private InventoryRegistryInterface $inventoryService,
        private Session $session,
        private ?Inertia $inertia = null
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = $this->auth->currentTenant();
        $query = (string) $request->query('q', '');
        $type = $request->query('type') ? (string) $request->query('type') : null;

        $items = $this->inventoryService->searchItems($query, $type, 50);
        $units = $this->inventoryService->listUnits();
        $categories = $this->inventoryService->listCategories();
        $lowStock = $this->inventoryService->listLowStockItems();

        if ($this->inertia) {
            return $this->inertia->render('Inventory/Index', [
                'items' => $items,
                'units' => $units,
                'categories' => $categories,
                'lowStockItems' => $lowStock,
                'filters' => [
                    'q' => $query,
                    'type' => $type,
                ],
                'currentTenant' => $tenant ? [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                ] : null,
            ]);
        }

        return Response::json([
            'items' => $items,
            'units' => $units,
            'categories' => $categories,
            'low_stock_items' => $lowStock,
        ]);
    }

    public function store(Request $request): Response
    {
        try {
            $user = $this->auth->user();
            $data = [
                'name' => $request->input('name'),
                'code' => $request->input('code'),
                'sku' => $request->input('sku'),
                'barcode' => $request->input('barcode'),
                'type' => $request->input('type', 'goods'),
                'category_id' => $request->input('category_id'),
                'unit_id' => $request->input('unit_id'),
                'cost_price' => (float) $request->input('cost_price', 0),
                'selling_price' => (float) $request->input('selling_price', 0),
                'initial_stock' => (float) $request->input('initial_stock', 0),
                'min_stock_alert' => (float) $request->input('min_stock_alert', 0),
                'track_inventory' => $request->input('track_inventory', true),
                'description' => $request->input('description'),
                'created_by' => $user ? (int) $user->id : null,
            ];

            $this->inventoryService->createItem($data);
            $this->session->flash('success', 'Item berhasil ditambahkan ke katalog.');
            return Response::redirect('/inventory');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/inventory');
        }
    }

    public function update(Request $request, int $id): Response
    {
        try {
            $this->inventoryService->updateItem($id, [
                'name' => $request->input('name'),
                'sku' => $request->input('sku'),
                'barcode' => $request->input('barcode'),
                'type' => $request->input('type'),
                'category_id' => $request->input('category_id'),
                'unit_id' => $request->input('unit_id'),
                'cost_price' => $request->input('cost_price'),
                'selling_price' => $request->input('selling_price'),
                'min_stock_alert' => $request->input('min_stock_alert'),
                'description' => $request->input('description'),
            ]);

            $this->session->flash('success', 'Data item berhasil diperbarui.');
            return Response::redirect('/inventory');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/inventory');
        }
    }

    public function adjustStock(Request $request, int $id): Response
    {
        try {
            $user = $this->auth->user();
            $quantity = (float) $request->input('quantity');
            $type = (string) $request->input('type', 'adjustment');
            $notes = (string) $request->input('notes', 'Penyesuaian stok manual');

            $this->inventoryService->recordStockMovement(
                itemId: $id,
                quantity: $quantity,
                type: $type,
                notes: $notes,
                userId: $user ? (int) $user->id : null
            );

            $this->session->flash('success', 'Mutasi stok fisik berhasil disimpan.');
            return Response::redirect('/inventory');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/inventory');
        }
    }
}
