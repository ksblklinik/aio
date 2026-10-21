<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Auth\Middleware\EnsureSuperadmin;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Modules\Controllers\ModuleManagerController;
use App\Modules\Workspaces\Models\Tenant;
use App\Modules\Workspaces\Models\User;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;
use Core\Inertia\Inertia;
use Core\Modules\ModuleManager;
use PHPUnit\Framework\TestCase;

class SuperadminTest extends TestCase
{
    private Connection $db;
    private Session $session;
    private AuthService $auth;
    private ModuleManager $moduleManager;
    private TenantContext $tenantContext;
    private User $superadmin;
    private User $regularOwner;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->db = new Connection('sqlite::memory:');
        $migrator = new Migrator($this->db);
        $migrator->runMigrationsFrom(__DIR__ . '/../../database/migrations');

        $this->session = new Session();
        $this->tenantContext = new TenantContext();
        $this->auth = new AuthService($this->db, $this->session, $this->tenantContext);
        $this->moduleManager = new ModuleManager($this->db->getPdo());

        // Create Superadmin user
        $this->superadmin = User::create($this->db, [
            'name' => 'System Superadmin',
            'email' => 'superadmin@aio.local',
            'password' => 'secret123',
            'is_superadmin' => 1,
        ]);

        // Create Regular user
        $this->regularOwner = User::create($this->db, [
            'name' => 'John Owner',
            'email' => 'john@acme.com',
            'password' => 'secret123',
            'is_superadmin' => 0,
        ]);

        // Create Tenant
        $this->tenant = Tenant::create($this->db, [
            'name' => 'Acme Corp',
            'slug' => 'acme',
            'plan' => 'pro',
        ]);

        $this->tenant->addMember($this->db, $this->superadmin->id, 'owner');
        $this->tenant->addMember($this->db, $this->regularOwner->id, 'owner');

        Inertia::getInstance()->setRequest(new Request('GET', '/modules'));
    }

    public function test_user_model_maps_is_superadmin_correctly(): void
    {
        $this->assertTrue($this->superadmin->isSuperadmin);
        $this->assertFalse($this->regularOwner->isSuperadmin);

        $fetchedSuper = User::findByEmail($this->db, 'superadmin@aio.local');
        $this->assertNotNull($fetchedSuper);
        $this->assertTrue($fetchedSuper->isSuperadmin);
    }

    public function test_auth_service_detects_superadmin(): void
    {
        $this->auth->login($this->regularOwner, $this->tenant->id);
        $this->assertFalse($this->auth->isSuperadmin());

        $this->auth->login($this->superadmin, $this->tenant->id);
        $this->assertTrue($this->auth->isSuperadmin());
    }

    public function test_ensure_superadmin_middleware_blocks_non_superadmin(): void
    {
        $middleware = new EnsureSuperadmin($this->auth, $this->session);

        // 1. Regular owner accessing superadmin route
        $this->auth->login($this->regularOwner, $this->tenant->id);
        $req = new Request('GET', '/admin/platform');
        $response = $middleware->handle($req, function () {
            return Response::html('Secret Platform Admin');
        });

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/dashboard', $response->getHeader('Location'));
        $this->assertStringContainsString('Superadmin', $this->session->getFlash('error') ?? '');

        // 2. Regular owner via JSON API
        $apiReq = new Request('GET', '/admin/platform', ['accept' => 'application/json']);
        $apiResponse = $middleware->handle($apiReq, function () {
            return Response::html('Secret Platform Admin');
        });

        $this->assertSame(403, $apiResponse->getStatusCode());

        // 3. Superadmin passes through
        $this->auth->login($this->superadmin, $this->tenant->id);
        $successResponse = $middleware->handle($req, function () {
            return Response::html('Platform Access Granted');
        });

        $this->assertSame(200, $successResponse->getStatusCode());
        $this->assertSame('Platform Access Granted', $successResponse->getBody());
    }

    public function test_superadmin_global_toggle_disables_module_for_all_tenants(): void
    {
        // Pastikan modul aktif default
        $this->assertTrue($this->moduleManager->isModuleActiveForTenant($this->tenant->id, 'projects'));

        // Superadmin matikan modul secara global
        $updated = $this->moduleManager->toggleGlobalActive('projects', false);
        $this->assertFalse($updated['is_active']);

        // Sekarang modul nonaktif untuk semua tenant (termasuk tenant baru)
        $this->assertFalse($this->moduleManager->isModuleActiveForTenant($this->tenant->id, 'projects'));

        $tenantB = Tenant::create($this->db, ['name' => 'Starlight', 'slug' => 'starlight']);
        $this->assertFalse($this->moduleManager->isModuleActiveForTenant($tenantB->id, 'projects'));
    }

    public function test_superadmin_can_toggle_globally_via_controller(): void
    {
        $controller = new ModuleManagerController($this->moduleManager, $this->auth, $this->session);

        // 1. Regular Owner tries scope=global -> Blocked with flash error
        $this->auth->login($this->regularOwner, $this->tenant->id);
        $reqOwner = new Request('POST', '/modules/toggle', [], ['id' => 'projects', 'scope' => 'global']);
        $controller->toggle($reqOwner);
        $this->assertStringContainsString('Hanya Platform Superadmin', $this->session->getFlash('error') ?? '');

        // 2. Superadmin tries scope=global -> Allowed
        $this->auth->login($this->superadmin, $this->tenant->id);
        $controller->toggle($reqOwner);
        $this->assertStringContainsString('di seluruh server', $this->session->getFlash('success') ?? '');
    }
}
