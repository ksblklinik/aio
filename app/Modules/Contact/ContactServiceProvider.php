<?php

declare(strict_types=1);

namespace App\Modules\Contact;

use App\Modules\Contact\Contracts\ContactRegistryInterface;
use App\Modules\Contact\Services\ContactService;
use Core\Database\Connection;
use Core\Database\TenantContext;
use Core\Modules\ModuleProvider;

class ContactServiceProvider extends ModuleProvider
{
    public function register(): void
    {
        $this->singleton(ContactRegistryInterface::class, function ($app) {
            return new ContactService(
                $app->get(Connection::class),
                $app->get(TenantContext::class)
            );
        });

        $this->singleton(ContactService::class, function ($app) {
            return $app->get(ContactRegistryInterface::class);
        });
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/routes.php');
    }
}
