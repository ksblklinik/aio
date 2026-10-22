<?php

declare(strict_types=1);

use App\Modules\Auth\Middleware\Authenticate;
use App\Modules\Notification\Controllers\NotificationController;
use Core\Router;

/** @var Router $router */

$router->group(['middleware' => [Authenticate::class]], function (Router $r) {
    $r->get('/notifications', [NotificationController::class, 'index']);
    $r->post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    $r->post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    $r->post('/notifications/{id}/delete', [NotificationController::class, 'delete']);
});
