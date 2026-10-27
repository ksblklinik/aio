<?php

declare(strict_types=1);

namespace Core\Modules;

use Core\Console\Kernel as ConsoleKernel;
use Core\Events\EventDispatcher;
use Core\Foundation\Application;
use Core\Inertia\Inertia;
use Core\Router;

abstract class ModuleProvider implements ModuleProviderInterface
{
    protected Application $app;
    protected string $modulePath;

    public function __construct(Application $app, string $modulePath = '')
    {
        $this->app = $app;
        $this->modulePath = rtrim($modulePath, '/\\');
    }

    public function register(): void
    {
    }

    public function boot(): void
    {
    }

    public function schedule(\Core\Schedule\Schedule $schedule): void
    {
    }

    public function getModulePath(): string
    {
        return $this->modulePath;
    }

    /**
     * Daftarkan binding transient ke service container
     */
    protected function bind(string $abstract, $concrete): void
    {
        $this->app->getContainer()->bind($abstract, $concrete);
    }

    /**
     * Daftarkan binding singleton ke service container
     */
    protected function singleton(string $abstract, $concrete): void
    {
        $this->app->getContainer()->singleton($abstract, $concrete);
    }

    /**
     * Daftarkan event listener untuk domain event
     */
    protected function listen(string $eventClass, $listener): void
    {
        if ($this->app->getContainer()->has(EventDispatcher::class)) {
            $dispatcher = $this->app->getContainer()->get(EventDispatcher::class);
            $dispatcher->listen($eventClass, $listener);
        }
    }

    /**
     * @var array<string|\Core\Console\Command>
     */
    protected static array $deferredCommands = [];

    /**
     * Daftarkan CLI command ke console kernel
     */
    protected function command(string|\Core\Console\Command $command): void
    {
        if ($this->app->getContainer()->has(ConsoleKernel::class)) {
            $kernel = $this->app->getContainer()->get(ConsoleKernel::class);
            $kernel->registerCommand($command);
        } else {
            self::$deferredCommands[] = $command;
        }
    }

    public static function flushDeferredCommands(ConsoleKernel $kernel): void
    {
        foreach (self::$deferredCommands as $cmd) {
            $kernel->registerCommand($cmd);
        }
        self::$deferredCommands = [];
    }

    /**
     * Muat rute HTTP dari file rute modul
     */
    protected function loadRoutesFrom(string $path): void
    {
        if (file_exists($path)) {
            $router = $this->app->getContainer()->get(Router::class);
            require $path;
        }
    }

    /**
     * Bagikan global props ke Inertia frontend
     */
    protected function shareInertia(array $props): void
    {
        if ($this->app->getContainer()->has(Inertia::class)) {
            $inertia = $this->app->getContainer()->get(Inertia::class);
            $inertia->share($props);
        }
    }
}
