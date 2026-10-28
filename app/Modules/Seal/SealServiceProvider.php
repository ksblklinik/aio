<?php

declare(strict_types=1);

namespace App\Modules\Seal;

use App\Modules\Seal\Contracts\DocumentSealRegistryInterface;
use App\Modules\Seal\Services\DocumentSealService;
use Core\Database\Connection;
use Core\Database\TenantContext;
use Core\Modules\ModuleProvider;

class SealServiceProvider extends ModuleProvider
{
    public function register(): void
    {
        $this->singleton(DocumentSealRegistryInterface::class, function ($app) {
            return new DocumentSealService(
                $app->get(Connection::class),
                $app->get(TenantContext::class)
            );
        });

        $this->singleton(DocumentSealService::class, function ($app) {
            return $app->get(DocumentSealRegistryInterface::class);
        });
    }

    public function boot(): void
    {
        if (file_exists(__DIR__ . '/routes.php')) {
            $this->loadRoutesFrom(__DIR__ . '/routes.php');
        }
    }
}
