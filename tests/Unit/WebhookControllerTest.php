<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Settings\Controllers\WebhookController;
use Core\Audit\AuditLogger;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use Core\Http\Request;
use Core\Http\Session;
use Core\Inertia\Inertia;
use Core\Queue\QueueManager;
use Core\Webhook\WebhookDispatcher;
use PHPUnit\Framework\TestCase;

class WebhookControllerTest extends TestCase
{
    private Connection $db;
    private Session $session;
    private TenantContext $tenantContext;
    private AuthService $auth;
    private QueueManager $queue;
    private WebhookDispatcher $dispatcher;
    private AuditLogger $auditLogger;
    private Inertia $inertia;
    private WebhookController $controller;
    private int $tenantId;
    private int $ownerId;
    private int $memberId;

    protected function setUp(): void
    {
        $this->db = new Connection('sqlite::memory:');
        $migrator = new Migrator($this->db);
        $migrator->runMigrationsFrom(dirname(__DIR__, 2) . '/database/migrations');

        $_SESSION = [];
        $this->session = new Session();
        $this->session->startArrayDriver();

        $this->tenantContext = new TenantContext();
        $this->auth = new AuthService($this->db, $this->session, $this->tenantContext);
        $this->queue = new QueueManager($this->db);
        $this->dispatcher = new WebhookDispatcher($this->db, $this->queue);
        $this->auditLogger = new AuditLogger($this->db, $this->tenantContext, $this->auth);
        $this->inertia = Inertia::getInstance();

        $this->controller = new WebhookController(
            $this->auth,
            $this->dispatcher,
            $this->auditLogger,
            $this->inertia,
            $this->session
        );

        // Seed tenant
        $this->tenantId = (int) $this->db->insert('tenants', [
            'name' => 'Acme Corp',
            'slug' => 'acme-corp',
            'plan' => 'pro',
        ]);

        // Seed owner
        $this->ownerId = (int) $this->db->insert('users', [
            'name' => 'Alice Owner',
            'email' => 'alice@acme.com',
            'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
            'is_superadmin' => 0,
        ]);

        $this->db->insert('tenant_user', [
            'tenant_id' => $this->tenantId,
            'user_id' => $this->ownerId,
            'role' => 'owner',
        ]);

        // Seed member
        $this->memberId = (int) $this->db->insert('users', [
            'name' => 'Bob Member',
            'email' => 'bob@acme.com',
            'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
            'is_superadmin' => 0,
        ]);

        $this->db->insert('tenant_user', [
            'tenant_id' => $this->tenantId,
            'user_id' => $this->memberId,
            'role' => 'member',
        ]);
    }

    public function testIndexBlocksNonOwnerAndNonAdmin(): void
    {
        // Login as member
        $this->auth->attempt('bob@acme.com', 'secret');

        $request = new Request('GET', '/settings/webhooks');
        $response = $this->controller->index($request);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertEquals('/dashboard', $response->getHeader('Location'));
        $this->assertStringContainsString('Hanya Owner atau Admin', $this->session->getFlash('error') ?? '');
    }

    public function testIndexRendersForOwner(): void
    {
        $this->auth->attempt('alice@acme.com', 'secret');

        // Pre-create one webhook
        $this->dispatcher->register($this->tenantId, 'My Webhook', 'https://example.com/webhook', ['project.created']);

        $request = new Request('GET', '/settings/webhooks', ['x-inertia' => 'true']);
        $this->inertia->setRequest($request);

        $response = $this->controller->index($request);

        $this->assertEquals(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringContainsString('Settings/Webhooks', $body);
        $this->assertStringContainsString('My Webhook', $body);
    }

    public function testStoreValidatesAndRegistersWebhook(): void
    {
        $this->auth->attempt('alice@acme.com', 'secret');

        $request = new Request('POST', '/settings/webhooks', [], [], [
            'name' => 'Slack Alerts',
            'url' => 'https://hooks.slack.com/services/123',
            'events' => ['project.created', 'project.updated'],
        ]);

        $response = $this->controller->store($request);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertEquals('/settings/webhooks', $response->getHeader('Location'));
        $this->assertStringContainsString('berhasil didaftarkan', $this->session->getFlash('success') ?? '');

        $webhooks = $this->dispatcher->getForTenant($this->tenantId);
        $this->assertCount(1, $webhooks);
        $this->assertEquals('Slack Alerts', $webhooks[0]['name']);
        $this->assertEquals('https://hooks.slack.com/services/123', $webhooks[0]['url']);
    }

    public function testStoreRejectsInvalidUrl(): void
    {
        $this->auth->attempt('alice@acme.com', 'secret');

        $request = new Request('POST', '/settings/webhooks', [], [], [
            'name' => 'Invalid Webhook',
            'url' => 'not-a-valid-url',
            'events' => ['*'],
        ]);

        $response = $this->controller->store($request);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertStringContainsString('URL', $this->session->getFlash('error') ?? '');
    }

    public function testToggleWebhookStatus(): void
    {
        $this->auth->attempt('alice@acme.com', 'secret');

        $hook = $this->dispatcher->register($this->tenantId, 'Toggle Me', 'https://example.com/toggle');
        $this->assertEquals(1, $hook['is_active']);

        $request = new Request('POST', "/settings/webhooks/{$hook['id']}/toggle");
        $response = $this->controller->toggle($request, (string) $hook['id']);

        $this->assertEquals(302, $response->getStatusCode());

        $updated = $this->dispatcher->find($this->tenantId, (int) $hook['id']);
        $this->assertNotNull($updated);
        $this->assertEquals(0, $updated['is_active']);
    }

    public function testDestroyWebhook(): void
    {
        $this->auth->attempt('alice@acme.com', 'secret');

        $hook = $this->dispatcher->register($this->tenantId, 'Delete Me', 'https://example.com/delete');

        $request = new Request('POST', "/settings/webhooks/{$hook['id']}/delete");
        $response = $this->controller->destroy($request, (string) $hook['id']);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertNull($this->dispatcher->find($this->tenantId, (int) $hook['id']));
    }

    public function testTestWebhookEnqueuesPing(): void
    {
        $this->auth->attempt('alice@acme.com', 'secret');

        $hook = $this->dispatcher->register($this->tenantId, 'Ping Target', 'https://example.com/ping');

        $request = new Request('POST', "/settings/webhooks/{$hook['id']}/test");
        $response = $this->controller->test($request, (string) $hook['id']);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertStringContainsString('uji coba ping', $this->session->getFlash('success') ?? '');
        $this->assertGreaterThan(0, $this->queue->size('webhooks'));
    }
}
