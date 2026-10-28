<?php

declare(strict_types=1);

namespace App\Modules\Tariff;

use App\Modules\Tariff\Contracts\TariffRegistryInterface;
use App\Modules\Tariff\Services\TariffService;
use Core\Database\Connection;
use Core\Database\TenantContext;
use Core\Modules\ModuleProvider;

class TariffServiceProvider extends ModuleProvider
{
    public function register(): void
    {
        $this->singleton(TariffRegistryInterface::class, function ($app) {
            return new TariffService(
                $app->get(Connection::class),
                $app->get(TenantContext::class)
            );
        });

        $this->singleton(TariffService::class, function ($app) {
            return $app->get(TariffRegistryInterface::class);
        });
    }

    public function boot(): void
    {
        if (file_exists(__DIR__ . '/routes.php')) {
            $this->loadRoutesFrom(__DIR__ . '/routes.php');
        }
    }
}
