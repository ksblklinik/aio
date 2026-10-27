<?php

declare(strict_types=1);

use App\Modules\Auth\Middleware\Authenticate;
use App\Modules\Inventory\Controllers\ApiInventoryController;
use App\Modules\Inventory\Controllers\InventoryController;
use App\Modules\Modules\Middleware\EnsureModuleActiveForTenant;
use Core\Router;

/** @var Router $router */

$router->group(['middleware' => [Authenticate::class, EnsureModuleActiveForTenant::module('inventory')]], function (Router $r) {
    // Web Routes
    $r->get('/inventory', [InventoryController::class, 'index']);
    $r->post('/inventory', [InventoryController::class, 'store']);
    $r->post('/inventory/{id}/update', [InventoryController::class, 'update']);
    $r->post('/inventory/{id}/adjust', [InventoryController::class, 'adjustStock']);

    // Programmatic Inter-Module API Routes
    $r->get('/api/inventory/search', [ApiInventoryController::class, 'search']);
    $r->get('/api/inventory/lookup', [ApiInventoryController::class, 'lookup']);
    $r->get('/api/inventory/low-stock', [ApiInventoryController::class, 'lowStock']);
    $r->get('/api/inventory/{id}', [ApiInventoryController::class, 'show']);
    $r->get('/api/inventory/{id}/check-stock', [ApiInventoryController::class, 'checkStock']);
    $r->post('/api/inventory/{id}/movement', [ApiInventoryController::class, 'storeMovement']);
});
