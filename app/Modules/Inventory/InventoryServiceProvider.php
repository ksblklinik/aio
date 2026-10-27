<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Modules\Inventory\Contracts\InventoryRegistryInterface;
use App\Modules\Inventory\Services\InventoryService;
use Core\Database\Connection;
use Core\Database\TenantContext;
use Core\Modules\ModuleProvider;

class InventoryServiceProvider extends ModuleProvider
{
    public function register(): void
    {
        $this->singleton(InventoryRegistryInterface::class, function ($app) {
            return new InventoryService(
                $app->get(Connection::class),
                $app->get(TenantContext::class)
            );
        });

        $this->singleton(InventoryService::class, function ($app) {
            return $app->get(InventoryRegistryInterface::class);
        });
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/routes.php');
    }
}
