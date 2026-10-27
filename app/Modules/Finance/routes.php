<?php

declare(strict_types=1);

use App\Modules\Auth\Middleware\Authenticate;
use App\Modules\Finance\Controllers\ApiFinanceController;
use App\Modules\Finance\Controllers\FinanceController;
use App\Modules\Modules\Middleware\EnsureModuleActiveForTenant;
use Core\Router;

/** @var Router $router */

$router->group(['middleware' => [Authenticate::class, EnsureModuleActiveForTenant::module('finance')]], function (Router $r) {
    // Web Routes
    $r->get('/finance', [FinanceController::class, 'index']);
    $r->post('/finance/accounts', [FinanceController::class, 'storeAccount']);
    $r->post('/finance/inflow', [FinanceController::class, 'storeInflow']);
    $r->post('/finance/outflow', [FinanceController::class, 'storeOutflow']);
    $r->post('/finance/transfer', [FinanceController::class, 'storeTransfer']);
    $r->post('/finance/transactions/{id}/approve', [FinanceController::class, 'approve']);
    $r->post('/finance/transactions/{id}/reject', [FinanceController::class, 'reject']);

    // Programmatic Inter-Module API Routes
    $r->get('/api/finance/accounts', [ApiFinanceController::class, 'accounts']);
    $r->get('/api/finance/categories', [ApiFinanceController::class, 'categories']);
    $r->get('/api/finance/transactions', [ApiFinanceController::class, 'transactions']);
    $r->get('/api/finance/transactions/{id}', [ApiFinanceController::class, 'showTransaction']);
    $r->post('/api/finance/inflow', [ApiFinanceController::class, 'storeInflow']);
    $r->post('/api/finance/outflow', [ApiFinanceController::class, 'storeOutflow']);
    $r->post('/api/finance/transfer', [ApiFinanceController::class, 'storeTransfer']);
    $r->get('/api/finance/reports/cash-flow', [ApiFinanceController::class, 'cashFlow']);
    $r->get('/api/finance/reports/profit-loss', [ApiFinanceController::class, 'profitLoss']);
    $r->get('/api/finance/reports/account-ledger/{accountId}', [ApiFinanceController::class, 'accountLedger']);
});
