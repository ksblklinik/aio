<?php

declare(strict_types=1);

use App\Modules\Auth\Middleware\Authenticate;
use App\Modules\Modules\Middleware\EnsureModuleActiveForTenant;
use App\Modules\Schedule\Controllers\ApiScheduleController;
use App\Modules\Schedule\Controllers\ScheduleController;
use Core\Router;

/** @var Router $router */

// Public / Reception TV Display Route (Bisa diakses untuk layar display ruang tunggu)
$router->get('/schedule/display', [ScheduleController::class, 'displayScreen']);

$router->group(['middleware' => [Authenticate::class, EnsureModuleActiveForTenant::module('schedule')]], function (Router $r) {
    // Web Routes
    $r->get('/schedule', [ScheduleController::class, 'index']);
    $r->post('/schedule/schedules', [ScheduleController::class, 'storeSchedule']);
    $r->post('/schedule/schedules/{id}/delete', [ScheduleController::class, 'deleteSchedule']);
    $r->post('/schedule/appointments', [ScheduleController::class, 'storeAppointment']);
    $r->post('/schedule/appointments/{id}/check-in', [ScheduleController::class, 'checkInAppointment']);
    $r->post('/schedule/appointments/{id}/cancel', [ScheduleController::class, 'cancelAppointment']);
    $r->post('/schedule/queue/issue', [ScheduleController::class, 'issueTicket']);
    $r->post('/schedule/queue/call-next', [ScheduleController::class, 'callNext']);
    $r->post('/schedule/queue/{id}/recall', [ScheduleController::class, 'recall']);
    $r->post('/schedule/queue/{id}/serving', [ScheduleController::class, 'serving']);
    $r->post('/schedule/queue/{id}/complete', [ScheduleController::class, 'complete']);
    $r->post('/schedule/queue/{id}/skip', [ScheduleController::class, 'skip']);
    $r->post('/schedule/queue/{id}/transfer', [ScheduleController::class, 'transfer']);

    // Programmatic Inter-Module, Mobile & Kiosk API Routes
    $r->get('/api/schedule/appointments', [ApiScheduleController::class, 'appointments']);
    $r->post('/api/schedule/appointments', [ApiScheduleController::class, 'storeAppointment']);
    $r->post('/api/schedule/appointments/{id}/check-in', [ApiScheduleController::class, 'checkIn']);
    $r->get('/api/schedule/queue/display', [ApiScheduleController::class, 'displayState']);
    $r->get('/api/schedule/queue/waiting', [ApiScheduleController::class, 'waitingTickets']);
    $r->get('/api/schedule/queue/metrics', [ApiScheduleController::class, 'metrics']);
    $r->post('/api/schedule/queue/issue', [ApiScheduleController::class, 'issueTicket']);
    $r->post('/api/schedule/queue/call-next', [ApiScheduleController::class, 'callNext']);
    $r->post('/api/schedule/queue/{id}/recall', [ApiScheduleController::class, 'recall']);
    $r->post('/api/schedule/queue/{id}/serving', [ApiScheduleController::class, 'serving']);
    $r->post('/api/schedule/queue/{id}/complete', [ApiScheduleController::class, 'complete']);
    $r->post('/api/schedule/queue/{id}/skip', [ApiScheduleController::class, 'skip']);
    $r->post('/api/schedule/queue/{id}/transfer', [ApiScheduleController::class, 'transfer']);
});
