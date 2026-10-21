<?php

declare(strict_types=1);

use App\Controllers\DashboardController;
use App\Controllers\HomeController;
use App\Modules\Auth\Controllers\DevPersonaController;
use App\Modules\Auth\Controllers\LoginController;
use App\Modules\Auth\Controllers\RegisterController;
use App\Modules\Auth\Middleware\Authenticate;
use App\Modules\Auth\Middleware\RedirectIfAuthenticated;
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

    // Core Module: Module Manager (Owner/Admin)
    $r->get('/modules', [\App\Modules\Modules\Controllers\ModuleManagerController::class, 'index']);
    $r->post('/modules/toggle', [\App\Modules\Modules\Controllers\ModuleManagerController::class, 'toggle']);
    $r->post('/modules/create', [\App\Modules\Modules\Controllers\ModuleManagerController::class, 'create']);
    $r->post('/modules/delete', [\App\Modules\Modules\Controllers\ModuleManagerController::class, 'destroy']);
});

// Dynamic Route Loader for Active Modules
try {
    $moduleManager = app(\Core\Modules\ModuleManager::class);
    if ($moduleManager instanceof \Core\Modules\ModuleManager) {
        foreach ($moduleManager->getActiveModules() as $activeModule) {
            $folder = $activeModule['folder'] ?? ucfirst($activeModule['id']);
            $moduleRouteFile = dirname(__DIR__) . "/app/Modules/{$folder}/routes.php";
            if (file_exists($moduleRouteFile)) {
                require $moduleRouteFile;
            }
        }
    }
} catch (\Throwable $e) {
    // Graceful fallback during initial boot
}

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
