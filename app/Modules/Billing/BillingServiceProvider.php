<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Modules\Billing\Contracts\BillingRegistryInterface;
use App\Modules\Billing\Services\BillingService;
use Core\Database\Connection;
use Core\Database\TenantContext;
use Core\Modules\ModuleProvider;

class BillingServiceProvider extends ModuleProvider
{
    public function register(): void
    {
        $this->singleton(BillingRegistryInterface::class, function ($app) {
            return new BillingService(
                $app->get(Connection::class),
                $app->get(TenantContext::class)
            );
        });

        $this->singleton(BillingService::class, function ($app) {
            return $app->get(BillingRegistryInterface::class);
        });
    }

    public function boot(): void
    {
        if (file_exists(__DIR__ . '/routes.php')) {
            $this->loadRoutesFrom(__DIR__ . '/routes.php');
        }
    }
}
