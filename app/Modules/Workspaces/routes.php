<?php

declare(strict_types=1);

use App\Modules\Auth\Middleware\Authenticate;
use App\Modules\Workspaces\Controllers\WorkspaceController;
use Core\Router;

/** @var Router $router */

$router->group(['middleware' => [Authenticate::class]], function (Router $r) {
    $r->post('/workspaces/switch', [WorkspaceController::class, 'switchWorkspace']);
});
