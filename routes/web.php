<?php

declare(strict_types=1);

use App\Controllers\DashboardController;
use App\Controllers\HomeController;
use App\Modules\Auth\Controllers\DevPersonaController;
use App\Modules\Auth\Controllers\LoginController;
use App\Modules\Auth\Controllers\RegisterController;
use App\Modules\Auth\Middleware\Authenticate;
use App\Modules\Auth\Middleware\RedirectIfAuthenticated;
use App\Modules\Projects\Controllers\ProjectController;
use App\Modules\Projects\Controllers\TaskController;
use App\Modules\Workspaces\Controllers\WorkspaceController;
use Core\Http\Response;
use Core\Router;

/** @var Router $router */

// Public Landing Page
$router->get('/', [HomeController::class, 'index']);

// Guest Routes (Redirect to /dashboard if already logged in)
$router->group(['middleware' => [RedirectIfAuthenticated::class]], function (Router $r) {
    $r->get('/login', [LoginController::class, 'showLoginForm']);
    $r->post('/login', [LoginController::class, 'login']);
    $r->get('/register', [RegisterController::class, 'showRegistrationForm']);
    $r->post('/register', [RegisterController::class, 'register']);
});

// Authenticated Routes
$router->group(['middleware' => [Authenticate::class]], function (Router $r) {
    $r->post('/logout', [LoginController::class, 'logout']);
    $r->get('/dashboard', [DashboardController::class, 'index']);

    // Workspace Tenancy Management
    $r->post('/workspaces/switch', [WorkspaceController::class, 'switchWorkspace']);

    // Business Module: Projects
    $r->get('/projects', [ProjectController::class, 'index']);
    $r->post('/projects', [ProjectController::class, 'store']);
    $r->post('/projects/{id}/update', [ProjectController::class, 'update']);
    $r->post('/projects/{id}/delete', [ProjectController::class, 'destroy']);

    // Business Module: Tasks
    $r->post('/tasks', [TaskController::class, 'store']);
    $r->post('/tasks/{id}/toggle', [TaskController::class, 'toggle']);
    $r->post('/tasks/{id}/delete', [TaskController::class, 'destroy']);
});

// Dev Fast Testing Persona Switcher (Local only)
$router->get('/_dev/switch-persona', [DevPersonaController::class, 'switchPersona']);

// System Health API
$router->get('/api/health', function () {
    return Response::json([
        'status' => 'ok',
        'framework' => 'aio',
        'php' => PHP_VERSION,
        'time' => date('c'),
    ]);
});
