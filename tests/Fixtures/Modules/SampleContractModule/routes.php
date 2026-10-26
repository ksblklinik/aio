<?php

declare(strict_types=1);

use Core\Http\Request;
use Core\Http\Response;
use Core\Router;

/** @var Router $router */

$router->get('/sample-contract/ping', function (Request $request): Response {
    return new Response(json_encode(['status' => 'ok', 'module' => 'sample_contract']), 200, ['Content-Type' => 'application/json']);
});
