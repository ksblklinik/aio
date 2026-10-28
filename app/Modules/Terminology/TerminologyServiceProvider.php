<?php

declare(strict_types=1);

namespace App\Modules\Terminology;

use App\Modules\Terminology\Contracts\TerminologyRegistryInterface;
use App\Modules\Terminology\Services\TerminologyService;
use Core\Database\Connection;
use Core\Database\TenantContext;
use Core\Modules\ModuleProvider;

class TerminologyServiceProvider extends ModuleProvider
{
    public function register(): void
    {
        $this->singleton(TerminologyRegistryInterface::class, function ($app) {
            return new TerminologyService(
                $app->get(Connection::class),
                $app->get(TenantContext::class)
            );
        });

        $this->singleton(TerminologyService::class, function ($app) {
            return $app->get(TerminologyRegistryInterface::class);
        });
    }

    public function boot(): void
    {
        if (file_exists(__DIR__ . '/routes.php')) {
            $this->loadRoutesFrom(__DIR__ . '/routes.php');
        }
    }
}
