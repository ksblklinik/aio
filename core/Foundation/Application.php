<?php

declare(strict_types=1);

namespace Core\Foundation;

use App\Modules\Auth\Services\AuthService;
use Core\Audit\AuditLogger;
use Core\Auth\ApiTokenService;
use Core\Config\Config;
use Core\Config\Env;
use Core\Console\Commands\ActivityListCommand;
use Core\Console\Commands\CreateSuperadminCommand;
use Core\Console\Commands\ListCommand;
use Core\Console\Commands\MakeModuleCommand;
use Core\Console\Commands\MigrateCommand;
use Core\Console\Commands\MigrateRollbackCommand;
use Core\Console\Commands\ModuleListCommand;
use Core\Console\Commands\NotificationListCommand;
use Core\Console\Commands\NotificationSendCommand;
use Core\Console\Commands\QueueFailedCommand;
use Core\Console\Commands\QueueRetryCommand;
use Core\Console\Commands\QueueWorkCommand;
use Core\Console\Commands\RouteListCommand;
use Core\Console\Commands\SeedCommand;
use Core\Console\Commands\TenantListCommand;
use Core\Console\Commands\TokenCreateCommand;
use Core\Console\Commands\TokenListCommand;
use Core\Console\Commands\TokenRevokeCommand;
use Core\Console\Commands\WebhookListCommand;
use Core\Console\Commands\WebhookTestCommand;
use Core\Console\Kernel;
use Core\Container;
use Core\Database\Connection;
use Core\Database\TenantContext;
use Core\Database\TenantScope;
use Core\Events\EventDispatcher;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;
use Core\Inertia\Inertia;
use Core\Middleware\StartSession;
use Core\Middleware\VerifyCsrfToken;
use Core\Modules\DefaultModuleProvider;
use Core\Modules\ModuleManager;
use Core\Modules\ModuleProviderInterface;
use Core\Notification\NotificationService;
use Core\Queue\QueueManager;
use Core\Router;
use Core\Validation\ValidationException;
use Core\Webhook\WebhookDispatcher;
use Throwable;

class Application
{
    private static ?self $instance = null;
    private Container $container;
    private string $basePath;
    /** @var array<string, \Core\Modules\ModuleProviderInterface> */
    private array $moduleProviders = [];
    private bool $moduleProvidersRegistered = false;
    private bool $moduleProvidersBooted = false;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = rtrim($basePath ?? dirname(__DIR__, 2), '/\\');
        self::$instance = $this;
        $this->container = Container::getInstance();
        $this->bootstrap();
    }

    public static function getInstance(): ?self
    {
        return self::$instance;
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    private function bootstrap(): void
    {
        // 1. Muat Environment Variables (.env)
        Env::loadFile($this->basePath . '/.env');

        // 2. Registrasi Binding Layanan Core
        $this->registerCoreBindings();

        // 3. Registrasi Module Providers untuk modul aktif
        $this->registerModuleProviders();
    }

    private function registerCoreBindings(): void
    {
        $c = $this->container;
        $base = $this->basePath;

        $c->singleton(self::class, fn() => $this);
        $c->singleton(Config::class, fn() => Config::getInstance());

        // Database Connection (PRAGMA WAL & FK diinisialisasi otomatis di Connection)
        $c->singleton(Connection::class, function () use ($base) {
            $path = config('database.path', $base . '/database.sqlite');
            return new Connection("sqlite:{$path}");
        });

        // Logger
        $c->singleton(\Core\Log\Logger::class, function () use ($base) {
            return new \Core\Log\Logger($base . '/storage/logs');
        });

        // Tenant Context & Scoping
        $c->singleton(TenantContext::class, fn() => TenantContext::getInstance());

        $c->singleton(TenantScope::class, function ($c) {
            return new TenantScope(
                $c->get(TenantContext::class),
                $c->get(Connection::class)
            );
        });

        // Session
        $c->singleton(Session::class, fn() => new Session());

        // AuthService
        $c->singleton(AuthService::class, function ($c) {
            return new AuthService(
                $c->get(Connection::class),
                $c->get(Session::class),
                $c->get(TenantContext::class)
            );
        });

        // ModuleManager
        $c->singleton(ModuleManager::class, function ($c) use ($base) {
            $db = $c->get(Connection::class);
            return new ModuleManager(
                $db->getPdo(),
                $base . '/app/Modules',
                $base . '/resources/js/Pages'
            );
        });

        // AuditLogger (mendukung request opsional baik di Web maupun CLI)
        $c->singleton(AuditLogger::class, function ($c) {
            $request = $c->has(Request::class) ? $c->get(Request::class) : null;
            return new AuditLogger(
                $c->get(Connection::class),
                $c->get(TenantContext::class),
                $c->get(AuthService::class),
                $request
            );
        });

        // API Token Service
        $c->singleton(ApiTokenService::class, function ($c) {
            return new ApiTokenService($c->get(Connection::class));
        });

        // Notification Service
        $c->singleton(NotificationService::class, function ($c) {
            return new NotificationService($c->get(Connection::class));
        });

        // Asynchronous Queue Manager
        $c->singleton(QueueManager::class, function ($c) {
            return new QueueManager($c->get(Connection::class));
        });

        // Outbound Webhook Dispatcher
        $c->singleton(WebhookDispatcher::class, function ($c) {
            return new WebhookDispatcher(
                $c->get(Connection::class),
                $c->get(QueueManager::class)
            );
        });

        // Domain Event Dispatcher
        $c->singleton(EventDispatcher::class, function ($c) {
            return new EventDispatcher(
                $c,
                $c->get(QueueManager::class),
                $c->get(WebhookDispatcher::class)
            );
        });

        // Router
        $c->singleton(Router::class, function ($c) {
            return new Router($c);
        });
    }

    public function registerModuleProviders(): void
    {
        if ($this->moduleProvidersRegistered) {
            return;
        }
        $this->moduleProvidersRegistered = true;

        if (!$this->container->has(ModuleManager::class)) {
            return;
        }

        try {
            $moduleManager = $this->container->get(ModuleManager::class);
            $activeModules = $moduleManager->getActiveModules();

            foreach ($activeModules as $module) {
                $id = (string) $module['id'];
                $folder = $module['folder'] ?? ucfirst($id);
                $modulePath = $this->basePath . '/app/Modules/' . $folder;

                $providerClass = $module['provider'] ?? null;
                if (empty($providerClass)) {
                    $defaultClass = "App\\Modules\\{$folder}\\{$folder}ServiceProvider";
                    if (class_exists($defaultClass)) {
                        $providerClass = $defaultClass;
                    }
                }

                if ($providerClass && class_exists($providerClass) && is_subclass_of($providerClass, ModuleProviderInterface::class)) {
                    $provider = new $providerClass($this, $modulePath);
                } else {
                    $provider = new DefaultModuleProvider($this, $modulePath);
                }

                $provider->register();
                $this->moduleProviders[$id] = $provider;
            }
        } catch (\Throwable $e) {
            // Graceful fallback during initial setup/migration
        }
    }

    public function bootModuleProviders(): void
    {
        if ($this->moduleProvidersBooted) {
            return;
        }
        $this->moduleProvidersBooted = true;

        // Pastikan seluruh provider sudah register
        $this->registerModuleProviders();

        foreach ($this->moduleProviders as $provider) {
            $provider->boot();
        }
    }

    /**
     * @return array<string, ModuleProviderInterface>
     */
    public function getModuleProviders(): array
    {
        return $this->moduleProviders;
    }

    public function getModuleProvider(string $id): ?ModuleProviderInterface
    {
        return $this->moduleProviders[$id] ?? null;
    }

    public function setupInertia(Request $request): Inertia
    {
        $c = $this->container;
        $session = $c->get(Session::class);
        $authService = $c->get(AuthService::class);
        $moduleManager = $c->get(ModuleManager::class);
        $notificationService = $c->get(NotificationService::class);
        $db = $c->get(Connection::class);

        $inertia = Inertia::getInstance();
        $inertia->setRequest($request);
        $inertia->setVersion('1.0.0');
        $inertia->share([
            'app' => [
                'name' => config('app.name', 'aio SaaS'),
                'env' => config('app.env', 'local'),
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

        $c->singleton(Inertia::class, fn() => $inertia);
        return $inertia;
    }

    public function handleHttp(Request $request): Response
    {
        $this->container->singleton(Request::class, fn() => $request);
        $this->setupInertia($request);

        $router = $this->container->get(Router::class);

        // Muat rute web
        $routesFile = $this->basePath . '/routes/web.php';
        if (file_exists($routesFile)) {
            require $routesFile;
        }

        // Boot module providers untuk memuat routes dan listeners modular
        $this->bootModuleProviders();

        $session = $this->container->get(Session::class);

        $globalMiddlewares = [
            StartSession::class,
            VerifyCsrfToken::class,
        ];

        try {
            $pipeline = array_reduce(
                array_reverse($globalMiddlewares),
                function ($next, $middlewareClass) {
                    return function (Request $req) use ($next, $middlewareClass) {
                        $middleware = $this->container->get($middlewareClass);
                        return $middleware->handle($req, $next);
                    };
                },
                function (Request $req) use ($router) {
                    return $router->dispatch($req);
                }
            );

            return $pipeline($request);
        } catch (ValidationException $e) {
            $session->flash('errors', $e->errors());
            $session->flash('error', $e->firstError());
            $referer = $request->header('referer') ?: '/';
            return Response::redirect($referer);
        } catch (Throwable $e) {
            return $this->handleHttpException($e, $request);
        }
    }

    private function handleHttpException(Throwable $e, Request $request): Response
    {
        $debug = (bool) config('app.debug', true);

        // Catat selalu ke file log terlepas dari status APP_DEBUG
        try {
            $logger = $this->container->get(\Core\Log\Logger::class);
            if ($logger instanceof \Core\Log\Logger) {
                $logger->error("HTTP 500: " . $e->getMessage(), [
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'uri' => $request->uri(),
                    'method' => $request->method(),
                ]);
            }
        } catch (Throwable) {
            // Abaikan kegagalan logger saat emergency
        }

        if ($request->isJson() || $request->isInertia()) {
            $payload = [
                'error' => 'Server Error',
                'message' => $debug ? $e->getMessage() : 'Terjadi kesalahan pada server.',
            ];

            if ($debug) {
                $payload['file'] = $e->getFile();
                $payload['line'] = $e->getLine();
                $payload['trace'] = explode("\n", $e->getTraceAsString());
            }

            return Response::json($payload, 500);
        }

        $message = htmlspecialchars($debug ? $e->getMessage() : 'Terjadi kesalahan internal pada server.');
        $details = $debug ? "<pre style=\"background:#1e293b;padding:1rem;border-radius:8px;overflow:auto\">" . htmlspecialchars($e->getTraceAsString()) . "</pre>" : '';

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>500 Internal Error</title>
</head>
<body style="font-family:sans-serif;padding:2rem;background:#090d16;color:#e2e8f0">
    <h1 style="color:#f43f5e">500 - Internal Server Error</h1>
    <p><strong>Message:</strong> {$message}</p>
    {$details}
</body>
</html>
HTML;

        return new Response($html, 500, ['content-type' => 'text/html; charset=utf-8']);
    }

    public function createCliKernel(): Kernel
    {
        $kernel = new Kernel($this->container);
        $this->container->singleton(Kernel::class, fn() => $kernel);

        // Muat rute web untuk inspect route:list
        $router = $this->container->get(Router::class);
        $routesFile = $this->basePath . '/routes/web.php';
        if (file_exists($routesFile)) {
            require $routesFile;
        }

        // Registrasi seluruh perintah CLI
        $kernel->register(new ListCommand())
            ->register(new MigrateCommand())
            ->register(new MigrateRollbackCommand())
            ->register(new SeedCommand())
            ->register(new MakeModuleCommand())
            ->register(new CreateSuperadminCommand())
            ->register(new ModuleListCommand())
            ->register(new TenantListCommand())
            ->register(new RouteListCommand())
            ->register(new ActivityListCommand())
            ->register(new TokenCreateCommand())
            ->register(new TokenListCommand())
            ->register(new TokenRevokeCommand())
            ->register(new NotificationSendCommand())
            ->register(new NotificationListCommand())
            ->register(new QueueWorkCommand())
            ->register(new QueueFailedCommand())
            ->register(new QueueRetryCommand())
            ->register(new WebhookListCommand())
            ->register(new WebhookTestCommand());

        // Boot module providers (memungkinkan modul mendaftarkan command CLI mereka)
        $this->bootModuleProviders();

        return $kernel;
    }

    public function handleCli(array $argv): int
    {
        $kernel = $this->createCliKernel();
        return $kernel->handle($argv);
    }
}
