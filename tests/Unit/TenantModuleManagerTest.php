<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Auth\Middleware\Authenticate;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Modules\Middleware\EnsureModuleActiveForTenant;
use App\Modules\Workspaces\Models\Tenant;
use App\Modules\Workspaces\Models\User;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;
use Core\Modules\ModuleManager;
use DomainException;
use PHPUnit\Framework\TestCase;

class TenantModuleManagerTest extends TestCase
{
    private Connection $db;
    private ModuleManager $manager;
    private Session $session;
    private AuthService $auth;
    private TenantContext $tenantContext;
    private Tenant $tenantA;
    private Tenant $tenantB;
    private User $user;

    protected function setUp(): void
    {
        $this->db = new Connection('sqlite::memory:');
        $migrator = new Migrator($this->db);
        $migrator->runMigrationsFrom(__DIR__ . '/../../database/migrations');

        $this->manager = new ModuleManager($this->db->getPdo());
        $this->session = new Session();
        $this->tenantContext = new TenantContext();
        $this->auth = new AuthService($this->db, $this->session, $this->tenantContext);

        // Seed user and 2 separate tenants
        $this->user = User::create($this->db, [
            'name' => 'Owner Multi-Tenant',
            'email' => 'owner@example.com',
            'password' => 'secret123',
        ]);

        $this->tenantA = Tenant::create($this->db, [
            'name' => 'Acme Corp',
            'slug' => 'acme-corp',
            'plan' => 'pro',
        ]);

        $this->tenantB = Tenant::create($this->db, [
            'name' => 'Starlight Labs',
            'slug' => 'starlight-labs',
            'plan' => 'enterprise',
        ]);

        $this->tenantA->addMember($this->db, $this->user->id, 'owner');
        $this->tenantB->addMember($this->db, $this->user->id, 'owner');
    }

    public function test_default_modules_are_active_for_both_tenants(): void
    {
        $this->assertTrue($this->manager->isModuleActiveForTenant($this->tenantA->id, 'projects'));
        $this->assertTrue($this->manager->isModuleActiveForTenant($this->tenantB->id, 'projects'));

        $navA = $this->manager->getActiveNavItemsForTenant($this->tenantA->id);
        $navB = $this->manager->getActiveNavItemsForTenant($this->tenantB->id);

        $idsA = array_column($navA, 'id');
        $idsB = array_column($navB, 'id');

        $this->assertContains('projects', $idsA);
        $this->assertContains('projects', $idsB);
    }

    public function test_tenant_a_disabling_module_does_not_affect_tenant_b(): void
    {
        // Tenant A menonaktifkan modul projects
        $updated = $this->manager->toggleActiveForTenant($this->tenantA->id, 'projects', false);
        $this->assertFalse($updated['is_active']);

        // Verifikasi isolasi status
        $this->assertFalse($this->manager->isModuleActiveForTenant($this->tenantA->id, 'projects'));
        $this->assertTrue($this->manager->isModuleActiveForTenant($this->tenantB->id, 'projects'));

        // Navigasi Tenant A tidak lagi memiliki projects
        $navA = $this->manager->getActiveNavItemsForTenant($this->tenantA->id);
        $idsA = array_column($navA, 'id');
        $this->assertNotContains('projects', $idsA);

        // Navigasi Tenant B tetap memiliki projects utuh
        $navB = $this->manager->getActiveNavItemsForTenant($this->tenantB->id);
        $idsB = array_column($navB, 'id');
        $this->assertContains('projects', $idsB);

        // Tenant A mengaktifkan kembali modul projects
        $reEnabled = $this->manager->toggleActiveForTenant($this->tenantA->id, 'projects', true);
        $this->assertTrue($reEnabled['is_active']);
        $this->assertTrue($this->manager->isModuleActiveForTenant($this->tenantA->id, 'projects'));
    }

    public function test_core_modules_cannot_be_disabled_for_any_tenant(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('dilindungi');

        $this->manager->toggleActiveForTenant($this->tenantA->id, 'modules', false);
    }

    public function test_middleware_blocks_access_when_module_is_disabled_for_tenant(): void
    {
        $middleware = new EnsureModuleActiveForTenant($this->auth, $this->manager, $this->session);

        // Login ke Tenant A
        $this->auth->login($this->user);
        $this->auth->switchTenant($this->tenantA->id);

        // Modul projects aktif secara default -> request lolos
        $req = new Request('GET', '/projects');
        $response = $middleware->handleWithModule($req, function () {
            return Response::html('OK Allowed');
        }, 'projects');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK Allowed', $response->getBody());

        // Tenant A mematikan modul projects
        $this->manager->toggleActiveForTenant($this->tenantA->id, 'projects', false);

        // Request ke /projects sekarang harus diblokir dan diredirect ke /dashboard
        $blockedResponse = $middleware->handleWithModule($req, function () {
            return Response::html('Should not be called');
        }, 'projects');

        $this->assertSame(302, $blockedResponse->getStatusCode());
        $this->assertSame('/dashboard', $blockedResponse->getHeader('Location'));
        $this->assertNotNull($this->session->getFlash('error'));

        // Request API / JSON mengembalikan HTTP 403 Forbidden
        $apiReq = new Request('GET', '/projects', ['accept' => 'application/json']);
        $jsonBlocked = $middleware->handleWithModule($apiReq, function () {
            return Response::html('Should not be called');
        }, 'projects');
        $this->assertSame(403, $jsonBlocked->getStatusCode());

        // Tenant B mengakses /projects tetap lolos (isolasi terjaga)
        $this->auth->switchTenant($this->tenantB->id);
        $responseTenantB = $middleware->handleWithModule($req, function () {
            return Response::html('Tenant B Allowed');
        }, 'projects');
        $this->assertSame(200, $responseTenantB->getStatusCode());
        $this->assertSame('Tenant B Allowed', $responseTenantB->getBody());
    }
}
