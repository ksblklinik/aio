<?php

declare(strict_types=1);

use App\Modules\Auth\Middleware\Authenticate;
use App\Modules\Modules\Controllers\ModuleManagerController;
use Core\Router;

/** @var Router $router */

$router->group(['middleware' => [Authenticate::class]], function (Router $r) {
    $r->get('/modules', [ModuleManagerController::class, 'index']);
    $r->post('/modules/toggle', [ModuleManagerController::class, 'toggle']);
    $r->post('/modules/create', [ModuleManagerController::class, 'create']);
    $r->post('/modules/delete', [ModuleManagerController::class, 'destroy']);
});
