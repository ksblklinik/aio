<?php

declare(strict_types=1);

namespace Core\Modules;

interface ModuleProviderInterface
{
    /**
     * Daftarkan dependency bindings, singletons, atau service factories
     */
    public function register(): void;

    /**
     * Bootstrapping modul: daftarkan routes, CLI commands, event listeners, shared Inertia props
     */
    public function boot(): void;
}
