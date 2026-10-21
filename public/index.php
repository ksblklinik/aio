<?php

declare(strict_types=1);

// Single Entry Point aio Web Application
define('AIO_START', microtime(true));

require_once __DIR__ . '/../vendor/autoload.php';

use Core\Container;
use Core\Http\Request;
use Core\Http\Session;
use Core\Inertia\Inertia;
use Core\Middleware\StartSession;
use Core\Middleware\VerifyCsrfToken;
use Core\Router;

// 1. Initialize Dependency Injection Container
$container = Container::getInstance();

// 2. Capture Incoming HTTP Request
$request = Request::capture();
$container->singleton(Request::class, fn() => $request);

// 3. Initialize Session & Inertia Bridge
$session = new Session();
$container->singleton(Session::class, fn() => $session);

$inertia = Inertia::getInstance();
$inertia->setRequest($request);
$inertia->setVersion('1.0.0');
$inertia->share([
    'app' => [
        'name' => 'aio SaaS',
        'env' => $_ENV['APP_ENV'] ?? 'local',
    ],
    'csrf_token' => fn() => $session->token(),
]);
$container->singleton(Inertia::class, fn() => $inertia);

// 4. Initialize Database & Tenant Context
$dbPath = dirname(__DIR__) . '/database.sqlite';
$db = new \Core\Database\Connection("sqlite:{$dbPath}");
$container->singleton(\Core\Database\Connection::class, fn() => $db);

$tenantContext = \Core\Database\TenantContext::getInstance();
// Default tenant context for demo
$tenantContext->setTenantId('tenant-acme', [
    'name' => 'Acme Cloud Platform',
    'plan' => 'Enterprise Tier',
]);
$container->singleton(\Core\Database\TenantContext::class, fn() => $tenantContext);

$tenantScope = new \Core\Database\TenantScope($tenantContext, $db);
$container->singleton(\Core\Database\TenantScope::class, fn() => $tenantScope);

// 5. Initialize Router & Middlewares
$router = new Router($container);
$container->singleton(Router::class, fn() => $router);

// 5. Load Routes Definition
require_once __DIR__ . '/../routes/web.php';

// 6. Global Middleware Stack Pipeline
$globalMiddlewares = [
    StartSession::class,
    VerifyCsrfToken::class,
];

// 7. Dispatch Request & Send Response
try {
    $pipeline = array_reduce(
        array_reverse($globalMiddlewares),
        function ($next, $middlewareClass) use ($container) {
            return function (Request $req) use ($next, $middlewareClass, $container) {
                $middleware = $container->get($middlewareClass);
                return $middleware->handle($req, $next);
            };
        },
        function (Request $req) use ($router) {
            return $router->dispatch($req);
        }
    );

    $response = $pipeline($request);
    $response->send();
} catch (\Throwable $e) {
    http_response_code(500);
    if (($request->isJson() || $request->isInertia())) {
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Server Error',
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ], JSON_PRETTY_PRINT);
    } else {
        echo "<!DOCTYPE html><html><head><title>500 Internal Error</title></head><body style=\"font-family:sans-serif;padding:2rem;background:#090d16;color:#e2e8f0\">";
        echo "<h1 style=\"color:#f43f5e\">500 - Internal Server Error</h1>";
        echo "<p><strong>Message:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<pre style=\"background:#1e293b;padding:1rem;border-radius:8px;overflow:auto\">" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
        echo "</body></html>";
    }
}
