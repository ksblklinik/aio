<?php

declare(strict_types=1);

use App\Modules\Auth\Middleware\Authenticate;
use App\Modules\Settings\Controllers\ApiTokenController;
use App\Modules\Settings\Controllers\OrganizationSettingsController;
use App\Modules\Settings\Controllers\WebhookController;
use Core\Router;

/** @var Router $router */

$router->group(['middleware' => [Authenticate::class]], function (Router $r) {
    // Organization Profile & Settings
    $r->get('/settings/organization', [OrganizationSettingsController::class, 'index']);
    $r->post('/settings/organization', [OrganizationSettingsController::class, 'update']);

    // API Tokens Management
    $r->get('/settings/api-tokens', [ApiTokenController::class, 'index']);
    $r->post('/settings/api-tokens', [ApiTokenController::class, 'store']);
    $r->post('/settings/api-tokens/{id}/delete', [ApiTokenController::class, 'destroy']);

    // Outbound Webhooks Management
    $r->get('/settings/webhooks', [WebhookController::class, 'index']);
    $r->post('/settings/webhooks', [WebhookController::class, 'store']);
    $r->post('/settings/webhooks/{id}/toggle', [WebhookController::class, 'toggle']);
    $r->post('/settings/webhooks/{id}/delete', [WebhookController::class, 'destroy']);
    $r->post('/settings/webhooks/{id}/test', [WebhookController::class, 'test']);
});
