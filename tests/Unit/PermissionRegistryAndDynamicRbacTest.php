<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Workspaces\Models\Tenant;
use App\Modules\Workspaces\Models\User;
use Core\Auth\AuthorizationException;
use Core\Auth\Gate;
use Core\Container;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use Core\Http\Request;
use Core\Http\Session;
use Core\Middleware\AuthorizePermission;
use Core\Router;
use PHPUnit\Framework\TestCase;

class PermissionRegistryAndDynamicRbacTest extends TestCase
{
    private Connection $db;
    private Container $container;
    private Session $session;
    private TenantContext $tenantContext;
    private AuthService $auth;
    private Gate $gate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new Connection('sqlite::memory:');
        $migrator = new Migrator($this->db);
        $migrator->runMigrationsFrom(dirname(__DIR__, 2) . '/database/migrations');

        $this->container = new Container();
        $this->container->singleton(Connection::class, fn() => $this->db);

        $this->session = new Session();
        $this->container->singleton(Session::class, fn() => $this->session);

        $this->tenantContext = new TenantContext();
        $this->container->singleton(TenantContext::class, fn() => $this->tenantContext);

        $this->auth = new AuthService($this->db, $this->session, $this->tenantContext);
        $this->container->singleton(AuthService::class, fn() => $this->auth);

        $this->gate = new Gate($this->db);
        $this->container->singleton(Gate::class, fn() => $this->gate);

        $this->container->singleton(AuthorizePermission::class, fn() => new AuthorizePermission($this->gate, $this->auth));
        Container::setInstance($this->container);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    private function createTenantAndUser(string $role = 'member', bool $isSuperadmin = false): array
    {
        $tenant = Tenant::create($this->db, [
            'name' => 'Acme Organization',
            'slug' => 'acme-' . uniqid(),
            'plan' => 'pro',
        ]);

        $user = User::create($this->db, [
            'name' => 'Test User',
            'email' => 'user-' . uniqid() . '@acme.com',
            'password' => 'secret123',
            'is_superadmin' => $isSuperadmin,
        ]);

        $tenant->addMember($this->db, (int) $user->id, $role);

        return [$tenant, $user];
    }

    public function test_gate_allows_superadmin_and_owner_all_permissions(): void
    {
        // 1. Superadmin
        [$tenant, $superadmin] = $this->createTenantAndUser('member', true);
        $this->assertTrue($this->gate->allows($superadmin, 'any.arbitrary.permission', (int) $tenant->id));

        // 2. Owner
        [$tenant2, $owner] = $this->createTenantAndUser('owner', false);
        $this->assertTrue($this->gate->allows($owner, 'projects.create', (int) $tenant2->id));
        $this->assertTrue($this->gate->allows($owner, 'billing.manage', (int) $tenant2->id));
    }

    public function test_gate_checks_dynamic_role_permissions_for_member_and_custom_roles(): void
    {
        [$tenant, $member] = $this->createTenantAndUser('member', false);
        $tenantId = (int) $tenant->id;

        // By default without explicitly assigned permissions, member is denied
        $this->assertFalse($this->gate->allows($member, 'projects.create', $tenantId));
        $this->assertTrue($this->gate->denies($member, 'projects.create', $tenantId));

        // Create 'member' role in roles table and assign 'projects.view' and 'projects.create'
        $this->db->statement("INSERT INTO roles (tenant_id, name, slug, is_system) VALUES (?, 'Member', 'member', 1)", [$tenantId]);
        $roleId = (int) $this->db->getPdo()->lastInsertId();

        $this->db->statement("INSERT INTO role_permissions (tenant_id, role_id, permission_key) VALUES (?, ?, 'projects.view')", [$tenantId, $roleId]);
        $this->db->statement("INSERT INTO role_permissions (tenant_id, role_id, permission_key) VALUES (?, ?, 'projects.create')", [$tenantId, $roleId]);

        // Clear Gate runtime cache
        $this->gate->clearCache();

        $this->assertTrue($this->gate->allows($member, 'projects.view', $tenantId));
        $this->assertTrue($this->gate->allows($member, 'projects.create', $tenantId));
        $this->assertFalse($this->gate->allows($member, 'projects.delete', $tenantId));
    }

    public function test_authorize_permission_middleware_blocks_unauthorized_user(): void
    {
        [$tenant, $member] = $this->createTenantAndUser('member', false);
        $this->session->set('auth_user_id', $member->id);
        $this->session->set('active_tenant_id', $tenant->id);

        $middleware = new AuthorizePermission($this->gate, $this->auth);
        $request = new Request('POST', '/projects/delete');

        $nextCalled = false;
        $next = function ($req) use (&$nextCalled) {
            $nextCalled = true;
            return \Core\Http\Response::json(['status' => 'deleted']);
        };

        $response = $middleware->handleWithPermission($request, $next, 'projects.delete');

        $this->assertFalse($nextCalled);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_router_supports_can_middleware_syntax(): void
    {
        [$tenant, $owner] = $this->createTenantAndUser('owner', false);
        $this->session->set('auth_user_id', $owner->id);
        $this->session->set('active_tenant_id', $tenant->id);

        $router = new Router($this->container);
        $router->post('/api/projects/create', fn() => \Core\Http\Response::json(['created' => true]), [
            'can:projects.create',
        ]);

        $request = new Request('POST', '/api/projects/create');
        $response = $router->dispatch($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertTrue($data['created']);
    }
}
