<?php

declare(strict_types=1);

namespace App\Modules\Projects;

use Core\Modules\ModuleProvider;

class ProjectsServiceProvider extends ModuleProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/routes.php');
    }
}
