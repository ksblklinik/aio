<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Api\Controllers\V1\ApiController;
use Core\Audit\AuditLogger;
use Core\Auth\ApiTokenService;
use Core\Container;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use Core\Database\TenantScope;
use Core\Http\Request;
use Core\Middleware\AuthenticateApiToken;
use Core\Router;
use PHPUnit\Framework\TestCase;

class ApiIntegrationTest extends TestCase
{
    private Connection $db;
    private Container $container;
    private ApiTokenService $tokenService;
    private TenantContext $tenantContext;
    private TenantScope $tenantScope;
    private AuditLogger $auditLogger;
    private Router $router;
    private int $tenantId;
    private int $userId;

    protected function setUp(): void
    {
        $this->db = new Connection('sqlite::memory:');
        $migrator = new Migrator($this->db);
        $migrator->runMigrationsFrom(dirname(__DIR__, 2) . '/database/migrations');
        $migrator->runMigrationsFrom(dirname(__DIR__, 2) . '/app/Modules/Projects/Database/Migrations', 'projects');

        $this->container = new Container();
        $this->container->singleton(Connection::class, fn() => $this->db);

        $this->tenantContext = new TenantContext();
        $this->container->singleton(TenantContext::class, fn() => $this->tenantContext);

        $this->tenantScope = new TenantScope($this->tenantContext, $this->db);
        $this->container->singleton(TenantScope::class, fn() => $this->tenantScope);

        $this->tokenService = new ApiTokenService($this->db);
        $this->container->singleton(ApiTokenService::class, fn() => $this->tokenService);

        $this->auditLogger = new AuditLogger($this->db, $this->tenantContext);
        $this->container->singleton(AuditLogger::class, fn() => $this->auditLogger);

        $this->router = new Router($this->container);
        $this->container->singleton(Router::class, fn() => $this->router);

        // Seed tenant & user
        $this->tenantId = (int) $this->db->insert('tenants', [
            'name' => 'API Alpha Workspace',
            'slug' => 'api-alpha',
            'plan' => 'enterprise',
        ]);

        $this->userId = (int) $this->db->insert('users', [
            'name' => 'API Developer',
            'email' => 'api@alpha.com',
            'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
            'is_superadmin' => 0,
        ]);

        $this->db->insert('tenant_user', [
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'role' => 'admin',
        ]);

        // Register API routes
        $this->router->group(['middleware' => [AuthenticateApiToken::class]], function (Router $r) {
            $r->get('/api/v1/me', [ApiController::class, 'me']);
            $r->get('/api/v1/projects', [ApiController::class, 'projects']);
            $r->post('/api/v1/projects', [ApiController::class, 'createProject']);
        });
    }

    public function test_api_me_returns_401_when_no_token_provided(): void
    {
        $request = new Request('GET', '/api/v1/me');
        $response = $this->router->dispatch($request);

        $this->assertSame(401, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertSame('Unauthorized', $data['error']);
        $this->assertStringContainsString('Authorization Bearer token wajib', $data['message']);
    }

    public function test_api_me_returns_401_for_invalid_token(): void
    {
        $request = new Request('GET', '/api/v1/me', headers: [
            'authorization' => 'Bearer aio_pat_invalid1234567890',
        ]);
        $response = $this->router->dispatch($request);

        $this->assertSame(401, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertSame('Unauthorized', $data['error']);
    }

    public function test_api_me_returns_200_for_valid_token(): void
    {
        $created = $this->tokenService->createToken($this->tenantId, $this->userId, 'Mobile Client');

        $request = new Request('GET', '/api/v1/me', headers: [
            'authorization' => 'Bearer ' . $created['plain_token'],
        ]);
        $response = $this->router->dispatch($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);

        $this->assertSame('success', $data['status']);
        $this->assertSame('API Developer', $data['user']['name']);
        $this->assertSame('API Alpha Workspace', $data['tenant']['name']);
        $this->assertSame('Mobile Client', $data['token']['name']);
    }

    public function test_api_projects_returns_tenant_isolated_projects(): void
    {
        // Insert project for this tenant
        $this->tenantContext->setTenantId((string) $this->tenantId);
        $this->tenantScope->insert('projects', [
            'user_id' => $this->userId,
            'name' => 'Alpha App Build',
            'status' => 'in_progress',
        ]);

        // Insert project for another tenant
        $this->db->statement("INSERT INTO tenants (id, name, slug) VALUES (999, 'Beta', 'beta')");
        $this->db->statement("INSERT INTO projects (tenant_id, user_id, name, status) VALUES (999, {$this->userId}, 'Beta Project', 'in_progress')");

        $created = $this->tokenService->createToken($this->tenantId, $this->userId, 'CI Pipeline');

        $request = new Request('GET', '/api/v1/projects', headers: [
            'authorization' => 'Bearer ' . $created['plain_token'],
        ]);
        $response = $this->router->dispatch($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);

        $this->assertSame('success', $data['status']);
        $this->assertCount(1, $data['data']);
        $this->assertSame('Alpha App Build', $data['data'][0]['name']);
    }

    public function test_api_blocks_action_when_token_lacks_ability(): void
    {
        // Token hanya memiliki kemampuan 'tasks:read' (bukan projects:read)
        $created = $this->tokenService->createToken(
            $this->tenantId,
            $this->userId,
            'Restricted Token',
            ['tasks:read']
        );

        $request = new Request('GET', '/api/v1/projects', headers: [
            'authorization' => 'Bearer ' . $created['plain_token'],
        ]);
        $response = $this->router->dispatch($request);

        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertSame('Forbidden', $data['error']);
        $this->assertStringContainsString('projects:read', $data['message']);
    }

    public function test_api_creates_project_and_logs_audit_event(): void
    {
        $created = $this->tokenService->createToken(
            $this->tenantId,
            $this->userId,
            'Automation Script',
            ['projects:write']
        );

        $request = new Request('POST', '/api/v1/projects', headers: [
            'authorization' => 'Bearer ' . $created['plain_token'],
        ], bodyParams: [
            'name' => 'Project from Webhook',
            'description' => 'Automated creation via Zapier',
            'status' => 'planned',
        ]);

        $response = $this->router->dispatch($request);

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);

        $this->assertSame('created', $data['status']);
        $this->assertSame('Project from Webhook', $data['project']['name']);
        $this->assertGreaterThan(0, $data['project']['id']);

        // Verifikasi audit log tercatat
        $logs = $this->auditLogger->getForTenant($this->tenantId);
        $this->assertCount(1, $logs);
        $this->assertSame('project.created', $logs[0]['action']);
        $this->assertStringContainsString('Project from Webhook', $logs[0]['description']);
    }
}
