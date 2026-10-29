<?php

declare(strict_types=1);

namespace App\Modules\Auth\Providers;

use App\Modules\Auth\Services\AuthService;
use Core\Auth\AuthServiceInterface;
use Core\Database\Connection;
use Core\Database\TenantContext;
use Core\Http\Session;
use Core\Modules\ModuleProvider;

class AuthServiceProvider extends ModuleProvider
{
    public function register(): void
    {
        $this->singleton(AuthService::class, function ($c) {
            return new AuthService(
                $c->get(Connection::class),
                $c->get(Session::class),
                $c->get(TenantContext::class)
            );
        });

        $this->singleton(AuthServiceInterface::class, function ($c) {
            return $c->get(AuthService::class);
        });
    }

    public function boot(): void
    {
    }
}
