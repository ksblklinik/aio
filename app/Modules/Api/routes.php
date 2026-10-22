<?php

declare(strict_types=1);

use App\Modules\Api\Controllers\V1\ApiController;
use Core\Middleware\AuthenticateApiToken;
use Core\Router;

/** @var Router $router */

$router->group(['middleware' => [AuthenticateApiToken::class]], function (Router $r) {
    $r->get('/api/v1/me', [ApiController::class, 'me']);
    $r->get('/api/v1/projects', [ApiController::class, 'projects']);
    $r->post('/api/v1/projects', [ApiController::class, 'createProject']);
});
