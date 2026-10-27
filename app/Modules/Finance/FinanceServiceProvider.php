<?php

declare(strict_types=1);

namespace App\Modules\Finance;

use App\Modules\Finance\Contracts\FinanceRegistryInterface;
use App\Modules\Finance\Services\FinanceService;
use Core\Database\Connection;
use Core\Database\TenantContext;
use Core\Ledger\LedgerManager;
use Core\Modules\ModuleProvider;

class FinanceServiceProvider extends ModuleProvider
{
    public function register(): void
    {
        $this->singleton(FinanceRegistryInterface::class, function ($app) {
            return new FinanceService(
                $app->get(Connection::class),
                $app->get(TenantContext::class),
                $app->get(LedgerManager::class)
            );
        });

        $this->singleton(FinanceService::class, function ($app) {
            return $app->get(FinanceRegistryInterface::class);
        });
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/routes.php');
    }
}
