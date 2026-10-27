<?php

declare(strict_types=1);

use App\Modules\Auth\Middleware\Authenticate;
use App\Modules\Billing\Controllers\ApiBillingController;
use App\Modules\Billing\Controllers\BillingController;
use App\Modules\Modules\Middleware\EnsureModuleActiveForTenant;
use Core\Router;

/** @var Router $router */

$router->group(['middleware' => [Authenticate::class, EnsureModuleActiveForTenant::module('billing')]], function (Router $r) {
    // Web Routes
    $r->get('/billing', [BillingController::class, 'index']);
    $r->post('/billing', [BillingController::class, 'store']);
    $r->get('/billing/{id}', [BillingController::class, 'show']);
    $r->post('/billing/{id}/issue', [BillingController::class, 'issue']);
    $r->post('/billing/{id}/payment', [BillingController::class, 'recordPayment']);
    $r->post('/billing/{id}/cancel', [BillingController::class, 'cancel']);
    $r->post('/billing/pos-checkout', [BillingController::class, 'quickCheckout']);
    $r->get('/billing/{id}/receipt', [BillingController::class, 'receipt']);

    // Programmatic REST API Routes
    $r->get('/api/billing/summary', [ApiBillingController::class, 'summary']);
    $r->get('/api/billing', [ApiBillingController::class, 'index']);
    $r->post('/api/billing', [ApiBillingController::class, 'store']);
    $r->get('/api/billing/{id}', [ApiBillingController::class, 'show']);
    $r->post('/api/billing/{id}/issue', [ApiBillingController::class, 'issue']);
    $r->post('/api/billing/{id}/payment', [ApiBillingController::class, 'payment']);
    $r->post('/api/billing/{id}/cancel', [ApiBillingController::class, 'cancel']);
    $r->post('/api/billing/pos-checkout', [ApiBillingController::class, 'quickCheckout']);
    $r->get('/api/billing/{id}/receipt', [ApiBillingController::class, 'receipt']);
});
