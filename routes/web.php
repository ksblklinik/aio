<?php

declare(strict_types=1);

use App\Controllers\DashboardController;
use App\Controllers\HomeController;
use App\Modules\Auth\Controllers\DevPersonaController;
use App\Modules\Auth\Controllers\ForgotPasswordController;
use App\Modules\Auth\Controllers\LoginController;
use App\Modules\Auth\Controllers\RegisterController;
use App\Modules\Auth\Controllers\ResetPasswordController;
use App\Modules\Auth\Middleware\Authenticate;
use App\Modules\Auth\Middleware\RedirectIfAuthenticated;
use App\Modules\Workspaces\Controllers\InvitationController;
use App\Modules\Workspaces\Controllers\WorkspaceController;
use Core\Http\Response;
use Core\Router;

/** @var Router $router */

// Public Landing Page
$router->get('/', [HomeController::class, 'index']);

// Guest Routes (Redirect to /dashboard if already logged in)
$router->group(['middleware' => [RedirectIfAuthenticated::class]], function (Router $r) {
    $r->get('/login', [LoginController::class, 'showLoginForm']);
    $r->post('/login', [LoginController::class, 'login'], ['throttle:10,1']);
    $r->get('/register', [RegisterController::class, 'showRegistrationForm']);
    $r->post('/register', [RegisterController::class, 'register'], ['throttle:10,1']);

    // Password Reset
    $r->get('/forgot-password', [ForgotPasswordController::class, 'showLinkRequestForm']);
    $r->post('/forgot-password', [ForgotPasswordController::class, 'sendResetLinkEmail'], ['throttle:5,1']);
    $r->get('/reset-password', [ResetPasswordController::class, 'showResetForm']);
    $r->post('/reset-password', [ResetPasswordController::class, 'reset'], ['throttle:5,1']);
});

// Workspace Invitation Acceptance
$router->get('/invitations/accept', [InvitationController::class, 'showAcceptForm']);
$router->post('/invitations/accept', [InvitationController::class, 'accept'], ['throttle:10,1']);

// Authenticated Routes
$router->group(['middleware' => [Authenticate::class]], function (Router $r) {
    $r->post('/logout', [LoginController::class, 'logout']);
    $r->get('/dashboard', [DashboardController::class, 'index']);
});

// Dynamic routes for active modules are booted via Module Providers in Application::bootModuleProviders()

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

// Universal Tenant Storage Download (Signed URL & Public files)
$router->get('/storage/download/{id}', [\Core\Storage\FileDownloadController::class, 'download']);

// Universal Server-Sent Events (SSE) Push Bus & Polling Endpoints
$router->get('/api/sse/stream', [\Core\Broadcasting\SseController::class, 'stream']);
$router->get('/api/sse/poll', [\Core\Broadcasting\SseController::class, 'poll']);


