<?php

declare(strict_types=1);

use App\Modules\Auth\Middleware\Authenticate;
use App\Modules\Modules\Middleware\EnsureModuleActiveForTenant;
use App\Modules\Seal\Controllers\ApiSealController;
use App\Modules\Seal\Controllers\PublicVerifyController;
use App\Modules\Seal\Controllers\SealController;
use Core\Router;

/** @var Router $router */

// 1. PUBLIC OPEN ROUTES (Zero-Trust Public Verification Portal via QR Code Scan)
$router->get('/verify/{token}', [PublicVerifyController::class, 'verify']);
$router->get('/api/verify/{token}', [PublicVerifyController::class, 'apiVerify']);

// 2. TENANT-PROTECTED BACK-OFFICE & REST API ROUTES
$router->group(['middleware' => [Authenticate::class, EnsureModuleActiveForTenant::module('seal')]], function (Router $r) {
    // Web Inertia Routes
    $r->get('/seal', [SealController::class, 'index']);
    $r->post('/seal/documents', [SealController::class, 'store']);
    $r->post('/seal/documents/{token}/revoke', [SealController::class, 'revoke']);

    // Programmatic Inter-Module REST API Routes
    $r->get('/api/seal/documents', [ApiSealController::class, 'index']);
    $r->post('/api/seal/documents', [ApiSealController::class, 'store']);
    $r->get('/api/seal/documents/{token}', [ApiSealController::class, 'show']);
    $r->post('/api/seal/documents/{token}/revoke', [ApiSealController::class, 'revoke']);
    $r->post('/api/seal/verify-payload', [ApiSealController::class, 'verifyPayload']);
});
