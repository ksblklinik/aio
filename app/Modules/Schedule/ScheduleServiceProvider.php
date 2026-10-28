<?php

declare(strict_types=1);

namespace App\Modules\Schedule;

use App\Modules\Schedule\Contracts\ScheduleRegistryInterface;
use App\Modules\Schedule\Services\ScheduleService;
use Core\Database\Connection;
use Core\Database\TenantContext;
use Core\Modules\ModuleProvider;

class ScheduleServiceProvider extends ModuleProvider
{
    public function register(): void
    {
        $this->singleton(ScheduleRegistryInterface::class, function ($app) {
            return new ScheduleService(
                $app->get(Connection::class),
                $app->get(TenantContext::class)
            );
        });

        $this->singleton(ScheduleService::class, function ($app) {
            return $app->get(ScheduleRegistryInterface::class);
        });
    }

    public function boot(): void
    {
        if (file_exists(__DIR__ . '/routes.php')) {
            $this->loadRoutesFrom(__DIR__ . '/routes.php');
        }
    }
}
