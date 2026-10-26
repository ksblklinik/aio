<?php

declare(strict_types=1);

namespace Tests\Fixtures\Modules\SampleContractModule;

use Core\Modules\ModuleProvider;

class SampleContractServiceProvider extends ModuleProvider
{
    public static bool $registerCalled = false;
    public static bool $bootCalled = false;
    public static bool $listenerExecuted = false;

    public function register(): void
    {
        self::$registerCalled = true;
        $this->bind('sample_contract.service', fn() => 'ContractServiceInstance');
    }

    public function boot(): void
    {
        self::$bootCalled = true;
        $this->listen(SampleContractEvent::class, function ($payload) {
            self::$listenerExecuted = true;
        });

        $routesFile = $this->getModulePath() . '/routes.php';
        if (file_exists($routesFile)) {
            $this->loadRoutesFrom($routesFile);
        }
    }
}

class SampleContractEvent
{
    public function __construct(public array $data = [])
    {
    }
}
