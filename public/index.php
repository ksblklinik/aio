<?php

declare(strict_types=1);

// Single Entry Point aio Web Application
define('AIO_START', microtime(true));

// Built-in PHP CLI Server: Layani file statis (CSS, JS, gambar) secara langsung
if (php_sapi_name() === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($path !== '/' && file_exists(__DIR__ . $path)) {
        return false;
    }
}

require_once __DIR__ . '/../vendor/autoload.php';

use Core\Container;
use Core\Http\Request;
use Core\Http\Response;
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

// 4. Initialize Database & Tenant Context
$dbPath = dirname(__DIR__) . '/database.sqlite';
$db = new \Core\Database\Connection("sqlite:{$dbPath}");
$container->singleton(\Core\Database\Connection::class, fn() => $db);

$tenantContext = \Core\Database\TenantContext::getInstance();
$container->singleton(\Core\Database\TenantContext::class, fn() => $tenantContext);

$authService = new \App\Modules\Auth\Services\AuthService($db, $session, $tenantContext);
$container->singleton(\App\Modules\Auth\Services\AuthService::class, fn() => $authService);

$tenantScope = new \Core\Database\TenantScope($tenantContext, $db);
$container->singleton(\Core\Database\TenantScope::class, fn() => $tenantScope);

$moduleManager = new \Core\Modules\ModuleManager($db->getPdo());
$container->singleton(\Core\Modules\ModuleManager::class, fn() => $moduleManager);

$auditLogger = new \Core\Audit\AuditLogger($db, $tenantContext, $authService, $request);
$container->singleton(\Core\Audit\AuditLogger::class, fn() => $auditLogger);

$apiTokenService = new \Core\Auth\ApiTokenService($db);
$container->singleton(\Core\Auth\ApiTokenService::class, fn() => $apiTokenService);

$notificationService = new \Core\Notification\NotificationService($db);
$container->singleton(\Core\Notification\NotificationService::class, fn() => $notificationService);

$queueManager = new \Core\Queue\QueueManager($db);
$container->singleton(\Core\Queue\QueueManager::class, fn() => $queueManager);

$webhookDispatcher = new \Core\Webhook\WebhookDispatcher($db, $queueManager);
$container->singleton(\Core\Webhook\WebhookDispatcher::class, fn() => $webhookDispatcher);

$inertia = Inertia::getInstance();
$inertia->setRequest($request);
$inertia->setVersion('1.0.0');
$inertia->share([
    'app' => [
        'name' => 'aio SaaS',
        'env' => $_ENV['APP_ENV'] ?? 'local',
    ],
    'csrf_token' => fn() => $session->token(),
    'auth' => fn() => [
        'user' => $authService->user() ? [
            'id' => $authService->user()->id,
            'name' => $authService->user()->name,
            'email' => $authService->user()->email,
            'is_superadmin' => $authService->user()->isSuperadmin,
        ] : null,
        'tenant' => $authService->currentTenant() ? [
            'id' => $authService->currentTenant()->id,
            'name' => $authService->currentTenant()->name,
            'slug' => $authService->currentTenant()->slug,
            'plan' => $authService->currentTenant()->plan,
        ] : null,
        'role' => $authService->currentRole(),
        'is_superadmin' => $authService->isSuperadmin(),
        'tenants' => $authService->user() ? $authService->user()->getTenants($db) : [],
    ],
    'active_modules' => function () use ($authService, $moduleManager) {
        $tenant = $authService->currentTenant();
        return $tenant 
            ? $moduleManager->getActiveNavItemsForTenant((int) $tenant->id) 
            : $moduleManager->getActiveNavItems();
    },
    'flash' => fn() => [
        'success' => $session->getFlash('success'),
        'error' => $session->getFlash('error'),
    ],
    'errors' => fn() => $session->getFlash('errors') ?: (object)[],
    'notifications' => function () use ($authService, $notificationService) {
        $user = $authService->user();
        $tenant = $authService->currentTenant();
        if (!$user || !$tenant) {
            return [
                'unread_count' => 0,
                'recent' => [],
            ];
        }
        $tenantId = (int) $tenant->id;
        $userId = (int) $user->id;
        return [
            'unread_count' => $notificationService->getUnreadCount($tenantId, $userId),
            'recent' => $notificationService->getForUser($tenantId, $userId, 5),
        ];
    },
]);
$container->singleton(Inertia::class, fn() => $inertia);

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
} catch (\Core\Validation\ValidationException $e) {
    $session->flash('errors', $e->errors());
    $session->flash('error', $e->firstError());
    $referer = $request->header('referer') ?: '/';
    $redirect = Response::redirect($referer);
    $redirect->send();
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
