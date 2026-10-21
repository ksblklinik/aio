<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Modules\Controllers\ModuleManagerController;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use Core\Http\Request;
use Core\Http\Session;
use Core\Inertia\Inertia;
use Core\Modules\ModuleManager;
use PHPUnit\Framework\TestCase;

class ModuleManagerControllerTest extends TestCase
{
    private Connection $db;
    private Session $session;
    private AuthService $auth;
    private ModuleManager $moduleManager;
    private ModuleManagerController $controller;

    protected function setUp(): void
    {
        $this->db = new Connection('sqlite::memory:');
        $migrator = new Migrator($this->db);
        $migrator->runMigrationsFrom(__DIR__ . '/../../database/migrations');

        $this->session = new Session();
        $tenantContext = new TenantContext();
        $this->auth = new AuthService($this->db, $this->session, $tenantContext);

        $this->moduleManager = new ModuleManager($this->db->getPdo());
        $this->controller = new ModuleManagerController($this->moduleManager, $this->auth, $this->session);

        Inertia::getInstance()->setRequest(new Request('GET', '/modules'));
    }

    public function test_member_role_is_blocked_from_accessing_modules(): void
    {
        // Register user
        $user = $this->auth->register('Member Bob', 'bob@acme.com', 'secret123', 'Acme');
        // Ubah role di database ke member
        $this->db->getPdo()->exec("UPDATE tenant_user SET role = 'member' WHERE user_id = {$user->id}");

        $response = $this->controller->index(new Request('GET', '/modules'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/dashboard', $response->getHeader('Location'));
        $this->assertStringContainsString('Hanya Owner atau Admin', $this->session->getFlash('error') ?? '');
    }

    public function test_owner_role_can_access_modules_page(): void
    {
        // Owner user
        $this->auth->register('Owner Alice', 'alice@acme.com', 'secret123', 'Acme Corp');

        $response = $this->controller->index(new Request('GET', '/modules'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_owner_can_toggle_module_active_status(): void
    {
        $this->auth->register('Owner Alice', 'alice@acme.com', 'secret123', 'Acme Corp');

        // Toggle non-core module 'projects'
        $request = new Request('POST', '/modules/toggle', [], ['id' => 'projects']);
        $response = $this->controller->toggle($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('dinonaktifkan', $this->session->getFlash('success') ?? '');

        // Toggle back to active
        $response2 = $this->controller->toggle($request);
        $this->assertStringContainsString('diaktifkan', $this->session->getFlash('success') ?? '');
    }

    public function test_owner_cannot_toggle_core_module(): void
    {
        $this->auth->register('Owner Alice', 'alice@acme.com', 'secret123', 'Acme Corp');

        $request = new Request('POST', '/modules/toggle', [], ['id' => 'auth']);
        $response = $this->controller->toggle($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('dilindungi dan tidak dapat dinonaktifkan', $this->session->getFlash('error') ?? '');
    }
}
