<?php

declare(strict_types=1);

use App\Modules\Auth\Middleware\Authenticate;
use App\Modules\Facility\Controllers\ApiFacilityController;
use App\Modules\Facility\Controllers\FacilityController;
use App\Modules\Modules\Middleware\EnsureModuleActiveForTenant;
use Core\Router;

/** @var Router $router */

$router->group(['middleware' => [Authenticate::class, EnsureModuleActiveForTenant::module('facility')]], function (Router $r) {
    // Web Routes
    $r->get('/facility', [FacilityController::class, 'index']);
    $r->post('/facility/locations', [FacilityController::class, 'storeLocation']);
    $r->put('/facility/locations/{id}', [FacilityController::class, 'updateLocation']);
    $r->delete('/facility/locations/{id}', [FacilityController::class, 'deleteLocation']);

    $r->post('/facility/rooms', [FacilityController::class, 'storeRoom']);
    $r->put('/facility/rooms/{id}', [FacilityController::class, 'updateRoom']);
    $r->delete('/facility/rooms/{id}', [FacilityController::class, 'deleteRoom']);

    $r->post('/facility/spaces', [FacilityController::class, 'storeSpace']);
    $r->put('/facility/spaces/{id}/status', [FacilityController::class, 'updateSpaceStatus']);
    $r->delete('/facility/spaces/{id}', [FacilityController::class, 'deleteSpace']);

    // Programmatic REST API Routes
    $r->get('/api/facility/summary', [ApiFacilityController::class, 'summary']);
    $r->get('/api/facility/locations', [ApiFacilityController::class, 'locations']);
    $r->post('/api/facility/locations', [ApiFacilityController::class, 'storeLocation']);
    $r->get('/api/facility/locations/{id}', [ApiFacilityController::class, 'showLocation']);
    $r->put('/api/facility/locations/{id}', [ApiFacilityController::class, 'updateLocation']);
    $r->delete('/api/facility/locations/{id}', [ApiFacilityController::class, 'deleteLocation']);

    $r->get('/api/facility/rooms', [ApiFacilityController::class, 'rooms']);
    $r->get('/api/facility/rooms/storage', [ApiFacilityController::class, 'storageRooms']);
    $r->post('/api/facility/rooms', [ApiFacilityController::class, 'storeRoom']);
    $r->get('/api/facility/rooms/{id}', [ApiFacilityController::class, 'showRoom']);
    $r->get('/api/facility/rooms/{id}/occupancy', [ApiFacilityController::class, 'roomOccupancy']);
    $r->put('/api/facility/rooms/{id}', [ApiFacilityController::class, 'updateRoom']);
    $r->delete('/api/facility/rooms/{id}', [ApiFacilityController::class, 'deleteRoom']);

    $r->get('/api/facility/spaces', [ApiFacilityController::class, 'spaces']);
    $r->post('/api/facility/spaces', [ApiFacilityController::class, 'storeSpace']);
    $r->get('/api/facility/spaces/{id}', [ApiFacilityController::class, 'showSpace']);
    $r->put('/api/facility/spaces/{id}/status', [ApiFacilityController::class, 'updateSpaceStatus']);
    $r->delete('/api/facility/spaces/{id}', [ApiFacilityController::class, 'deleteSpace']);
});
