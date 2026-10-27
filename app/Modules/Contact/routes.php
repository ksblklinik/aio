<?php

declare(strict_types=1);

use App\Modules\Auth\Middleware\Authenticate;
use App\Modules\Contact\Controllers\ApiContactController;
use App\Modules\Contact\Controllers\ContactController;
use App\Modules\Modules\Middleware\EnsureModuleActiveForTenant;
use Core\Router;

/** @var Router $router */

$router->group(['middleware' => [Authenticate::class, EnsureModuleActiveForTenant::module('contact')]], function (Router $r) {
    // Web Routes
    $r->get('/contacts', [ContactController::class, 'index']);
    $r->post('/contacts', [ContactController::class, 'store']);
    $r->post('/contacts/{id}/update', [ContactController::class, 'update']);
    $r->post('/contacts/{id}/delete', [ContactController::class, 'destroy']);

    // Programmatic Inter-Module API Routes
    $r->get('/api/contacts/search', [ApiContactController::class, 'search']);
    $r->get('/api/contacts/{id}', [ApiContactController::class, 'show']);
    $r->post('/api/contacts', [ApiContactController::class, 'store']);
});
