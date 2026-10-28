<?php

declare(strict_types=1);

namespace App\Modules\Facility;

use App\Modules\Facility\Contracts\FacilityRegistryInterface;
use App\Modules\Facility\Services\FacilityService;
use Core\Database\Connection;
use Core\Database\TenantContext;
use Core\Modules\ModuleProvider;

class FacilityServiceProvider extends ModuleProvider
{
    public function register(): void
    {
        $this->singleton(FacilityRegistryInterface::class, function ($app) {
            return new FacilityService(
                $app->get(Connection::class),
                $app->get(TenantContext::class)
            );
        });

        $this->singleton(FacilityService::class, function ($app) {
            return $app->get(FacilityRegistryInterface::class);
        });
    }

    public function boot(): void
    {
        if (file_exists(__DIR__ . '/routes.php')) {
            $this->loadRoutesFrom(__DIR__ . '/routes.php');
        }
    }
}
