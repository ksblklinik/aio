<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Audit\AuditLogger;
use Core\Auth\ApiTokenService;
use Core\Database\Connection;
use Core\Database\TenantContext;
use Core\Database\TenantScope;
use Core\Events\EventDispatcher;
use Core\Foundation\Application;
use Core\Http\Request;
use Core\Http\Session;
use Core\Modules\ModuleManager;
use Core\Notification\NotificationService;
use Core\Queue\QueueManager;
use Core\Router;
use Core\Webhook\WebhookDispatcher;
use PHPUnit\Framework\TestCase;

class ApplicationKernelTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Application(dirname(__DIR__, 2));
    }

    public function test_application_initializes_and_binds_core_services_identically(): void
    {
        $c = $this->app->getContainer();

        $this->assertTrue($c->has(Connection::class));
        $this->assertTrue($c->has(TenantContext::class));
        $this->assertTrue($c->has(TenantScope::class));
        $this->assertTrue($c->has(Session::class));
        $this->assertTrue($c->has(ModuleManager::class));
        $this->assertTrue($c->has(AuditLogger::class));
        $this->assertTrue($c->has(ApiTokenService::class));
        $this->assertTrue($c->has(NotificationService::class));
        $this->assertTrue($c->has(QueueManager::class));
        $this->assertTrue($c->has(WebhookDispatcher::class));
        $this->assertTrue($c->has(EventDispatcher::class));
        $this->assertTrue($c->has(Router::class));

        // Instance resolvable
        $this->assertInstanceOf(Connection::class, $c->get(Connection::class));
        $this->assertInstanceOf(TenantContext::class, $c->get(TenantContext::class));
        $this->assertInstanceOf(AuditLogger::class, $c->get(AuditLogger::class));
    }

    public function test_application_creates_cli_kernel_with_registered_commands(): void
    {
        $kernel = $this->app->createCliKernel();
        $commands = $kernel->getCommands();

        $this->assertArrayHasKey('list', $commands);
        $this->assertArrayHasKey('migrate', $commands);
        $this->assertArrayHasKey('make:module', $commands);
        $this->assertArrayHasKey('user:create-superadmin', $commands);
        $this->assertArrayHasKey('module:list', $commands);
        $this->assertArrayHasKey('tenant:list', $commands);
        $this->assertArrayHasKey('route:list', $commands);
        $this->assertArrayHasKey('queue:work', $commands);
        $this->assertArrayHasKey('webhook:list', $commands);
    }

    public function test_application_dispatches_http_request(): void
    {
        $request = new Request('GET', '/api/health');
        $response = $this->app->handleHttp($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertSame('ok', $data['status']);
        $this->assertSame('aio', $data['framework']);
    }
}
