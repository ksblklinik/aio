<?php

declare(strict_types=1);

namespace Core\Modules;

class DefaultModuleProvider extends ModuleProvider
{
    public function boot(): void
    {
        if ($this->modulePath !== '') {
            $routesFile = $this->modulePath . '/routes.php';
            if (file_exists($routesFile)) {
                $this->loadRoutesFrom($routesFile);
            }
        }
    }
}
