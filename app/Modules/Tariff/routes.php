<?php

declare(strict_types=1);

use App\Modules\Auth\Middleware\Authenticate;
use App\Modules\Modules\Middleware\EnsureModuleActiveForTenant;
use App\Modules\Tariff\Controllers\ApiTariffController;
use App\Modules\Tariff\Controllers\TariffController;
use Core\Router;

/** @var Router $router */

$router->group(['middleware' => [Authenticate::class, EnsureModuleActiveForTenant::module('tariff')]], function (Router $r) {
    // Web Inertia Routes
    $r->get('/tariff', [TariffController::class, 'index']);
    $r->post('/tariff/price-books', [TariffController::class, 'storePriceBook']);
    $r->post('/tariff/price-books/{id}', [TariffController::class, 'updatePriceBook']);
    $r->post('/tariff/price-books/{id}/delete', [TariffController::class, 'deletePriceBook']);
    $r->post('/tariff/rates', [TariffController::class, 'storeRate']);
    $r->post('/tariff/rates/{id}/delete', [TariffController::class, 'deleteRate']);
    $r->post('/tariff/split-rules', [TariffController::class, 'storeSplitRule']);
    $r->post('/tariff/split-rules/{id}/delete', [TariffController::class, 'deleteSplitRule']);
    $r->post('/tariff/settle', [TariffController::class, 'settleFees']);

    // Programmatic Inter-Module & POS/Kiosk REST API Routes
    $r->get('/api/tariff/price-books', [ApiTariffController::class, 'priceBooks']);
    $r->post('/api/tariff/price-books', [ApiTariffController::class, 'storePriceBook']);
    $r->get('/api/tariff/rates', [ApiTariffController::class, 'rates']);
    $r->post('/api/tariff/rates', [ApiTariffController::class, 'storeRate']);
    $r->post('/api/tariff/resolve-price', [ApiTariffController::class, 'resolvePrice']);
    $r->post('/api/tariff/calculate-splits', [ApiTariffController::class, 'calculateSplits']);
    $r->get('/api/tariff/allocations', [ApiTariffController::class, 'allocations']);
    $r->get('/api/tariff/beneficiary-summary', [ApiTariffController::class, 'beneficiarySummary']);
    $r->post('/api/tariff/settle', [ApiTariffController::class, 'settle']);
});
