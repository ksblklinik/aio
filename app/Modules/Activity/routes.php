<?php

declare(strict_types=1);

use App\Modules\Activity\Controllers\ActivityLogController;
use App\Modules\Auth\Middleware\Authenticate;
use Core\Router;

/** @var Router $router */

$router->group(['middleware' => [Authenticate::class]], function (Router $r) {
    $r->get('/activity', [ActivityLogController::class, 'index']);
});
