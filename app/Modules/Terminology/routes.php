<?php

declare(strict_types=1);

use App\Modules\Auth\Middleware\Authenticate;
use App\Modules\Modules\Middleware\EnsureModuleActiveForTenant;
use App\Modules\Terminology\Controllers\ApiTerminologyController;
use App\Modules\Terminology\Controllers\TerminologyController;
use Core\Router;

/** @var Router $router */

$router->group(['middleware' => [Authenticate::class, EnsureModuleActiveForTenant::module('terminology')]], function (Router $r) {
    // Web Inertia Routes
    $r->get('/terminology', [TerminologyController::class, 'index']);
    $r->post('/terminology/code-systems', [TerminologyController::class, 'storeCodeSystem']);
    $r->post('/terminology/code-systems/{id}', [TerminologyController::class, 'updateCodeSystem']);
    $r->post('/terminology/code-systems/{id}/delete', [TerminologyController::class, 'deleteCodeSystem']);

    $r->post('/terminology/concepts', [TerminologyController::class, 'storeConcept']);
    $r->post('/terminology/concepts/{id}/delete', [TerminologyController::class, 'deleteConcept']);
    $r->post('/terminology/concepts/bulk-import', [TerminologyController::class, 'bulkImport']);

    $r->post('/terminology/mappings', [TerminologyController::class, 'storeMapping']);
    $r->post('/terminology/mappings/{id}/delete', [TerminologyController::class, 'deleteMapping']);

    // Programmatic Inter-Module & Autocomplete REST API Routes
    $r->get('/api/terminology/search', [ApiTerminologyController::class, 'search']);
    $r->get('/api/terminology/code-systems', [ApiTerminologyController::class, 'codeSystems']);
    $r->post('/api/terminology/code-systems', [ApiTerminologyController::class, 'storeCodeSystem']);
    $r->get('/api/terminology/concepts', [ApiTerminologyController::class, 'concepts']);
    $r->post('/api/terminology/concepts', [ApiTerminologyController::class, 'storeConcept']);
    $r->get('/api/terminology/mappings', [ApiTerminologyController::class, 'mappings']);
    $r->post('/api/terminology/mappings', [ApiTerminologyController::class, 'storeMapping']);
    $r->post('/api/terminology/resolve-mapping', [ApiTerminologyController::class, 'resolveMapping']);
    $r->post('/api/terminology/bulk-import', [ApiTerminologyController::class, 'bulkImport']);
});
