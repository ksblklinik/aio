<?php

declare(strict_types=1);

namespace Core\Foundation;

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
use Core\Console\Commands\ScheduleRunCommand;
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
use Core\Auth\Gate;
use Core\Middleware\AuthorizePermission;
use Core\Middleware\StartSession;
use Core\Middleware\VerifyCsrfToken;
use Core\Modules\DefaultModuleProvider;
use Core\Modules\ModuleManager;
use Core\Modules\ModuleProviderInterface;
use Core\Notification\NotificationService;
use Core\Queue\QueueManager;
use Core\Storage\LocalStorageDriver;
use Core\Storage\StorageDriverInterface;
use Core\Storage\StorageManager;
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

        // Database Manager & Multi-Connection Resolver
        $c->singleton(\Core\Database\DatabaseManager::class, function () use ($base) {
            $default = (string) config('database.default', 'sqlite');
            $connections = (array) config('database.connections', []);
            if (!isset($connections['sqlite'])) {
                $connections['sqlite'] = [
                    'driver' => 'sqlite',
                    'database' => config('database.path', $base . '/database.sqlite'),
                ];
            }
            return new \Core\Database\DatabaseManager($default, $connections);
        });

        // Default Database Connection (PRAGMA WAL & FK diinisialisasi otomatis di Connection)
        $c->singleton(Connection::class, function ($c) {
            $conn = $c->get(\Core\Database\DatabaseManager::class)->connection();
            if ((bool) config('diagnostics.profiler_enabled', true)) {
                $conn->setProfiler($c->get(\Core\Diagnostics\QueryProfiler::class));
            }
            return $conn;
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
            $auth = $c->has(\Core\Auth\AuthServiceInterface::class) ? $c->get(\Core\Auth\AuthServiceInterface::class) : null;
            return new AuditLogger(
                $c->get(Connection::class),
                $c->get(TenantContext::class),
                $auth,
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

        // Authorization Gate & Middleware
        $c->singleton(Gate::class, function ($c) {
            return new Gate($c->get(Connection::class));
        });

        $c->singleton(AuthorizePermission::class, function ($c) {
            $auth = $c->has(\Core\Auth\AuthServiceInterface::class) ? $c->get(\Core\Auth\AuthServiceInterface::class) : null;
            return new AuthorizePermission($c->get(Gate::class), $auth);
        });

        // Universal Tenant-Isolated File Storage
        $c->singleton(StorageDriverInterface::class, function () use ($base) {
            $root = (string) config('storage.disks.local.root', $base . '/storage/app');
            return new LocalStorageDriver($root);
        });

        $c->singleton(StorageManager::class, function ($c) {
            $driver = $c->get(StorageDriverInterface::class);
            $db = $c->has(Connection::class) ? $c->get(Connection::class) : null;
            $appKey = (string) (config('app.key') ?: 'aio-universal-secret-key-32chars!!');
            return new StorageManager($driver, $db, $appKey);
        });

        // Universal Tenant Settings
        $c->singleton(\Core\Settings\TenantSettings::class, function ($c) {
            return new \Core\Settings\TenantSettings($c->get(Connection::class));
        });

        // Atomic Document Sequence Generator
        $c->singleton(\Core\Sequence\SequenceManager::class, function ($c) {
            return new \Core\Sequence\SequenceManager($c->get(Connection::class));
        });

        // Scheduled Task Engine
        $c->singleton(\Core\Schedule\Schedule::class, function () {
            return new \Core\Schedule\Schedule();
        });

        // Universal Mailer Service (mendukung driver log & smtp dinamis via config)
        $c->singleton(\Core\Mail\Mailer::class, function ($c) use ($base) {
            $logPath = (string) config('mail.mailers.log.path', $base . '/storage/logs/mail.log');
            $logDriver = new \Core\Mail\LogMailerDriver($logPath);

            $smtpConfig = (array) config('mail.mailers.smtp', []);
            $smtpDriver = new \Core\Mail\SmtpMailerDriver($smtpConfig);

            $defaultDriverName = (string) config('mail.default', 'log');
            $defaultDriver = $defaultDriverName === 'smtp' ? $smtpDriver : $logDriver;

            $queue = $c->has(QueueManager::class) ? $c->get(QueueManager::class) : null;
            $mailer = new \Core\Mail\Mailer($defaultDriver, $queue);
            $mailer->setDriver('log', $logDriver);
            $mailer->setDriver('smtp', $smtpDriver);

            return $mailer;
        });

        // Account Lifecycle Service (Invitations & Password Reset)
        $c->singleton(\Core\Mail\AccountLifecycleService::class, function ($c) {
            return new \Core\Mail\AccountLifecycleService(
                $c->get(Connection::class),
                $c->get(\Core\Mail\Mailer::class)
            );
        });

        // Throttle Requests Middleware
        $c->singleton(\Core\Middleware\ThrottleRequests::class, function () use ($base) {
            return new \Core\Middleware\ThrottleRequests($base . '/storage/framework/ratelimit');
        });

        // Vector Store & Semantic Embeddings Engine
        $c->singleton(\Core\Vector\SqliteVectorDriver::class, function ($c) {
            return new \Core\Vector\SqliteVectorDriver(
                $c->get(Connection::class),
                $c->get(TenantContext::class)
            );
        });

        $c->singleton(\Core\Vector\VectorManager::class, function ($c) {
            $sqliteDriver = $c->get(\Core\Vector\SqliteVectorDriver::class);
            return new \Core\Vector\VectorManager($sqliteDriver, 'sqlite');
        });

        // Unified AI Gateway & LLM Orchestrator
        $c->singleton(\Core\Ai\AiManager::class, function () {
            $default = (string) config('ai.default', env('AI_DRIVER', 'mock'));
            $providers = (array) config('ai.providers', []);
            $mockDriver = new \Core\Ai\MockAiDriver();
            $manager = new \Core\Ai\AiManager($mockDriver, $default, $providers);
            $manager->setDriver('mock', $mockDriver);
            return $manager;
        });

        // Universal Atomic Financial Ledger Engine
        $c->singleton(\Core\Ledger\LedgerManager::class, function ($c) {
            return new \Core\Ledger\LedgerManager(
                $c->get(Connection::class),
                $c->get(TenantContext::class)
            );
        });

        // Idempotency Guard Middleware
        $c->singleton(\Core\Middleware\IdempotentRequest::class, function ($c) use ($base) {
            return new \Core\Middleware\IdempotentRequest(
                $base . '/storage/framework/idempotency',
                $c->get(TenantContext::class)
            );
        });

        // Lightweight Declarative Workflow Engine & State Machine
        $c->singleton(\Core\Workflow\WorkflowManager::class, function ($c) {
            return new \Core\Workflow\WorkflowManager(
                $c->get(Connection::class),
                $c->get(TenantContext::class),
                $c->has(Gate::class) ? $c->get(Gate::class) : null
            );
        });

        // High-Performance Tenant-Isolated Cache Engine
        $c->singleton(\Core\Cache\CacheManager::class, function ($c) use ($base) {
            $cachePath = $base . '/storage/framework/cache';
            $fileDriver = new \Core\Cache\FileCacheDriver($cachePath);
            $arrayDriver = new \Core\Cache\ArrayCacheDriver();
            $manager = new \Core\Cache\CacheManager($fileDriver, $c->get(TenantContext::class), 'file');
            $manager->setDriver('array', $arrayDriver);
            $manager->setDriver('file', $fileDriver);
            return $manager;
        });

        // Multi-Channel Notification Broadcaster
        $c->singleton(\Core\Notification\ChannelManager::class, function ($c) {
            $manager = new \Core\Notification\ChannelManager($c->get(TenantContext::class));
            $manager->registerChannel('database', new \Core\Notification\Channels\DatabaseChannel($c->get(\Core\Notification\NotificationService::class)));
            $manager->registerChannel('email', new \Core\Notification\Channels\EmailChannel($c->has(\Core\Mail\MailerDriverInterface::class) ? $c->get(\Core\Mail\MailerDriverInterface::class) : null));
            $manager->registerChannel('whatsapp', new \Core\Notification\Channels\WhatsAppChannel((string) config('services.whatsapp.url', env('WA_GATEWAY_URL')), (string) config('services.whatsapp.token', env('WA_GATEWAY_TOKEN'))));
            $manager->registerChannel('telegram', new \Core\Notification\Channels\TelegramChannel((string) config('services.telegram.token', env('TELEGRAM_BOT_TOKEN'))));
            return $manager;
        });

        // Dynamic Custom Field Registry
        $c->singleton(\Core\CustomField\FieldManager::class, function ($c) {
            return new \Core\CustomField\FieldManager(
                $c->get(Connection::class),
                $c->get(TenantContext::class)
            );
        });

        // Unified Payment Gateway Driver Contract & Manager
        $c->singleton(\Core\Payment\PaymentManager::class, function ($c) {
            return new \Core\Payment\PaymentManager(
                $c->get(Connection::class),
                $c->get(\Core\Ledger\LedgerManager::class),
                $c->get(TenantContext::class)
            );
        });

        // Tenant Feature Entitlement & Usage Quota Engine
        $c->singleton(\Core\Billing\QuotaManager::class, function ($c) {
            return new \Core\Billing\QuotaManager(
                $c->get(Connection::class),
                $c->get(TenantContext::class)
            );
        });

        // Universal Streamed Import & Export Manager
        $c->singleton(\Core\Export\ExporterManager::class, function ($c) {
            $pdo = $c->has(Connection::class) ? $c->get(Connection::class)->getPdo() : null;
            return new \Core\Export\ExporterManager($pdo);
        });

        // Authenticated AES-256-GCM Encrypter
        $c->singleton(\Core\Security\Encrypter::class, function () {
            $key = \Core\Security\AppKey::get();
            return new \Core\Security\Encrypter($key);
        });

        // Native Two-Factor Authentication / TOTP Engine
        $c->singleton(\Core\Security\Totp::class, function () {
            return new \Core\Security\Totp();
        });

        // Device Integrity & Anti-Fraud Guard
        $c->singleton(\Core\Security\IntegrityGuard::class, function () {
            $key = \Core\Security\AppKey::get();
            return new \Core\Security\IntegrityGuard($key);
        });

        // Security Middlewares
        $c->singleton(\Core\Middleware\SecurityHeaders::class, fn() => new \Core\Middleware\SecurityHeaders());
        $c->singleton(\Core\Middleware\SanitizeInput::class, fn() => new \Core\Middleware\SanitizeInput());

        // Universal Server-Sent Events (SSE) Push Bus
        $c->singleton(\Core\Broadcasting\SseBroadcasterInterface::class, function ($c) {
            return new \Core\Broadcasting\SseBroadcaster(
                $c->get(Connection::class),
                $c->get(TenantContext::class)
            );
        });
        $c->singleton(\Core\Broadcasting\SseBroadcaster::class, function ($c) {
            return $c->get(\Core\Broadcasting\SseBroadcasterInterface::class);
        });

        // Universal Atomic Mutex & Concurrency Lock Engine
        $c->singleton(\Core\Lock\Drivers\LockDriverInterface::class, function ($c) {
            return new \Core\Lock\Drivers\DatabaseLockDriver($c->get(Connection::class));
        });
        $c->singleton(\Core\Lock\LockManagerInterface::class, function ($c) {
            return new \Core\Lock\LockManager($c->get(\Core\Lock\Drivers\LockDriverInterface::class));
        });
        $c->singleton(\Core\Lock\LockManager::class, function ($c) {
            return $c->get(\Core\Lock\LockManagerInterface::class);
        });

        // Universal Disaster Recovery & Hot-Backup Engine
        $c->singleton(\Core\Backup\Drivers\BackupDriverInterface::class, function ($c) use ($base) {
            $db = $c->get(Connection::class);
            $dbPath = (string) config('database.connections.sqlite.database', $base . '/database.sqlite');
            return new \Core\Backup\Drivers\SqliteBackupDriver($db, $dbPath);
        });
        $c->singleton(\Core\Backup\BackupManagerInterface::class, function ($c) use ($base) {
            $db = $c->get(Connection::class);
            $driver = $c->get(\Core\Backup\Drivers\BackupDriverInterface::class);
            $backupDir = (string) config('backup.path', $base . '/storage/backups');
            $appKey = \Core\Security\AppKey::get();
            return new \Core\Backup\BackupManager($db, $driver, $backupDir, $appKey);
        });
        $c->singleton(\Core\Backup\BackupManager::class, function ($c) {
            return $c->get(\Core\Backup\BackupManagerInterface::class);
        });

        // Universal Cross-Module Integrity Auditor & Automated Data Reconciler
        $c->singleton(\Core\Reconciliation\ReconciliationManagerInterface::class, function ($c) {
            return new \Core\Reconciliation\ReconciliationManager($c->get(Connection::class));
        });
        $c->singleton(\Core\Reconciliation\ReconciliationManager::class, function ($c) {
            return $c->get(\Core\Reconciliation\ReconciliationManagerInterface::class);
        });

        // Platform Resilience Watchdog & Dead-Letter Queue
        $c->singleton(\Core\Queue\DeadLetterQueueInterface::class, function ($c) {
            return new \Core\Queue\DeadLetterQueue($c->get(Connection::class));
        });
        $c->singleton(\Core\Queue\DeadLetterQueue::class, function ($c) {
            return $c->get(\Core\Queue\DeadLetterQueueInterface::class);
        });
        $c->singleton(\Core\Resilience\PlatformWatchdogInterface::class, function ($c) use ($base) {
            $dbPath = (string) config('database.connections.sqlite.database', $base . '/database.sqlite');
            return new \Core\Resilience\PlatformWatchdog($c->get(Connection::class), $dbPath);
        });
        $c->singleton(\Core\Resilience\PlatformWatchdog::class, function ($c) {
            return $c->get(\Core\Resilience\PlatformWatchdogInterface::class);
        });

        // Universal Transactional Outbox Pattern & Reliable Event Bus
        $c->singleton(\Core\Outbox\TransactionalOutboxManagerInterface::class, function ($c) {
            $db = $c->get(Connection::class);
            $eventDispatcher = $c->has(\Core\Events\EventDispatcher::class) ? $c->get(\Core\Events\EventDispatcher::class) : null;
            $sse = $c->has(\Core\Broadcasting\SseBroadcasterInterface::class) ? $c->get(\Core\Broadcasting\SseBroadcasterInterface::class) : null;
            $webhooks = $c->has(\Core\Webhook\WebhookDispatcher::class) ? $c->get(\Core\Webhook\WebhookDispatcher::class) : null;

            return new \Core\Outbox\TransactionalOutboxManager($db, $eventDispatcher, $sse, $webhooks);
        });
        $c->singleton(\Core\Outbox\TransactionalOutboxManager::class, function ($c) {
            return $c->get(\Core\Outbox\TransactionalOutboxManagerInterface::class);
        });

        // Universal Tenant Crypto-Shredding, Data Portability & GDPR/PDP Privacy Engine
        $c->singleton(\Core\Privacy\TenantKeyManager::class, function ($c) {
            $db = $c->get(Connection::class);
            $appKey = \Core\Security\AppKey::get();
            return new \Core\Privacy\TenantKeyManager($db, $appKey);
        });
        $c->singleton(\Core\Privacy\DataPortabilityExporter::class, function ($c) {
            return new \Core\Privacy\DataPortabilityExporter($c->get(Connection::class));
        });
        $c->singleton(\Core\Privacy\TenantDataShredder::class, function ($c) {
            $db = $c->get(Connection::class);
            $keyManager = $c->get(\Core\Privacy\TenantKeyManager::class);
            $appKey = \Core\Security\AppKey::get();
            return new \Core\Privacy\TenantDataShredder($db, $keyManager, $appKey);
        });
        $c->singleton(\Core\Privacy\TenantPrivacyManagerInterface::class, function ($c) {
            return new \Core\Privacy\TenantPrivacyManager(
                $c->get(\Core\Privacy\TenantKeyManager::class),
                $c->get(\Core\Privacy\DataPortabilityExporter::class),
                $c->get(\Core\Privacy\TenantDataShredder::class)
            );
        });
        $c->singleton(\Core\Privacy\TenantPrivacyManager::class, function ($c) {
            return $c->get(\Core\Privacy\TenantPrivacyManagerInterface::class);
        });

        // Universal Runtime Performance Sentinel & SQLite Profiler
        $c->singleton(\Core\Diagnostics\QueryProfiler::class, function () use ($base) {
            $threshold = (float) config('diagnostics.slow_query_threshold_ms', 25.0);
            $maxBuffer = (int) config('diagnostics.slow_query_max_buffer', 100);
            $storagePath = config('diagnostics.slow_query_storage_path')
                ? (string) config('diagnostics.slow_query_storage_path')
                : $base . '/storage/diagnostics/slow_queries.json';
            return new \Core\Diagnostics\QueryProfiler($threshold, $maxBuffer, $storagePath);
        });
        $c->singleton(\Core\Diagnostics\MemorySentinel::class, function () {
            $threshold = (int) config('diagnostics.memory_warning_threshold_bytes', 67108864);
            return new \Core\Diagnostics\MemorySentinel($threshold);
        });
        $c->singleton(\Core\Diagnostics\PerformanceSentinelInterface::class, function ($c) {
            $db = $c->get(Connection::class);
            $profiler = $c->get(\Core\Diagnostics\QueryProfiler::class);
            $memory = $c->get(\Core\Diagnostics\MemorySentinel::class);

            // Connect profiler to database connection
            $db->setProfiler($profiler);

            return new \Core\Diagnostics\PerformanceSentinel($db, $profiler, $memory);
        });
        $c->singleton(\Core\Diagnostics\PerformanceSentinel::class, function ($c) {
            return $c->get(\Core\Diagnostics\PerformanceSentinelInterface::class);
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

        $schedule = $this->container->get(\Core\Schedule\Schedule::class);

        foreach ($this->moduleProviders as $provider) {
            $provider->boot();
            $provider->schedule($schedule);
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
        $authService = $c->has(\Core\Auth\AuthServiceInterface::class) ? $c->get(\Core\Auth\AuthServiceInterface::class) : null;
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
                'user' => $authService?->user() ? [
                    'id' => $authService->user()->id,
                    'name' => $authService->user()->name,
                    'email' => $authService->user()->email,
                    'is_superadmin' => !empty($authService->user()->isSuperadmin),
                ] : null,
                'tenant' => $authService?->currentTenant() ? [
                    'id' => $authService->currentTenant()->id,
                    'name' => $authService->currentTenant()->name,
                    'slug' => $authService->currentTenant()->slug,
                    'plan' => $authService->currentTenant()->plan,
                ] : null,
                'role' => (method_exists($authService, 'currentRole')) ? $authService->currentRole() : null,
                'is_superadmin' => (method_exists($authService, 'isSuperadmin')) ? $authService->isSuperadmin() : false,
                'tenants' => ($authService?->user() && method_exists($authService->user(), 'getTenants')) ? $authService->user()->getTenants($db) : [],
                'permissions' => function () use ($authService, $c) {
                    $user = $authService?->user();
                    $tenant = $authService?->currentTenant();
                    if (!$user || !$tenant) {
                        return [];
                    }
                    /** @var Gate $gate */
                    $gate = $c->get(Gate::class);
                    return $gate->getPermissionsForUser($user, (int) $tenant->id);
                },
            ],
            'active_modules' => function () use ($authService, $moduleManager) {
                $tenant = $authService?->currentTenant();
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
            if ($request->wantsJson() && !$request->isInertia()) {
                return Response::json([
                    'error' => 'Validation Error',
                    'message' => $e->firstError(),
                    'errors' => $e->errors(),
                ], 422);
            }

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
            ->register(new WebhookTestCommand())
            ->register(new ScheduleRunCommand())
            ->register(new \Core\Console\Commands\SsePruneCommand())
            ->register(new \Core\Console\Commands\LockPruneCommand())
            ->register(new \Core\Console\Commands\LockListCommand())
            ->register(new \Core\Console\Commands\DatabaseBackupCommand())
            ->register(new \Core\Console\Commands\DatabaseRestoreCommand())
            ->register(new \Core\Console\Commands\DatabaseVerifyCommand())
            ->register(new \Core\Console\Commands\DatabaseHealCommand())
            ->register(new \Core\Console\Commands\DatabasePruneBackupsCommand())
            ->register(new \Core\Console\Commands\ReconcileAuditCommand())
            ->register(new \Core\Console\Commands\ReconcileSyncCommand())
            ->register(new \Core\Console\Commands\WatchdogHealthCommand())
            ->register(new \Core\Console\Commands\CircuitListCommand())
            ->register(new \Core\Console\Commands\CircuitResetCommand())
            ->register(new \Core\Console\Commands\QueueDlqListCommand())
            ->register(new \Core\Console\Commands\QueueDlqRetryCommand())
            ->register(new \Core\Console\Commands\QueueDlqPurgeCommand())
            ->register(new \Core\Console\Commands\OutboxRelayCommand())
            ->register(new \Core\Console\Commands\OutboxDrainCommand())
            ->register(new \Core\Console\Commands\OutboxStatusCommand())
            ->register(new \Core\Console\Commands\OutboxPruneCommand())
            ->register(new \Core\Console\Commands\TenantExportCommand())
            ->register(new \Core\Console\Commands\TenantShredCommand())
            ->register(new \Core\Console\Commands\TenantCryptoShredCommand())
            ->register(new \Core\Console\Commands\KeyGenerateCommand())
            ->register(new \Core\Console\Commands\SentinelProfileCommand())
            ->register(new \Core\Console\Commands\SentinelSlowQueriesCommand())
            ->register(new \Core\Console\Commands\SentinelStatusCommand());

        // Boot module providers (memungkinkan modul mendaftarkan command CLI mereka)
        $this->bootModuleProviders();

        \Core\Modules\ModuleProvider::flushDeferredCommands($kernel);

        return $kernel;
    }

    public function handleCli(array $argv): int
    {
        $kernel = $this->createCliKernel();
        return $kernel->handle($argv);
    }
}
