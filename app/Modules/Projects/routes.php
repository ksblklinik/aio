<?php

declare(strict_types=1);

use App\Modules\Auth\Middleware\Authenticate;
use App\Modules\Modules\Middleware\EnsureModuleActiveForTenant;
use App\Modules\Projects\Controllers\ProjectController;
use App\Modules\Projects\Controllers\TaskController;
use Core\Router;

/** @var Router $router */

$router->group(['middleware' => [Authenticate::class, EnsureModuleActiveForTenant::module('projects')]], function (Router $r) {
    // Projects CRUD
    $r->get('/projects', [ProjectController::class, 'index']);
    $r->post('/projects', [ProjectController::class, 'store']);
    $r->post('/projects/{id}/update', [ProjectController::class, 'update']);
    $r->post('/projects/{id}/delete', [ProjectController::class, 'destroy']);

    // Tasks Management
    $r->post('/tasks', [TaskController::class, 'store']);
    $r->post('/tasks/{id}/toggle', [TaskController::class, 'toggle']);
    $r->post('/tasks/{id}/delete', [TaskController::class, 'destroy']);
});
