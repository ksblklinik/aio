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

    /**
     * Daftarkan recurring task ke internal task scheduler
     */
    public function schedule(\Core\Schedule\Schedule $schedule): void;
}
