<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Activity\Controllers\ActivityLogController;
use App\Modules\Auth\Services\AuthService;
use Core\Audit\AuditLogger;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use Core\Http\Request;
use Core\Http\Session;
use Core\Inertia\Inertia;
use PHPUnit\Framework\TestCase;

class ActivityLogControllerTest extends TestCase
{
    private Connection $db;
    private Session $session;
    private TenantContext $tenantContext;
    private AuthService $auth;
    private AuditLogger $auditLogger;
    private Inertia $inertia;
    private ActivityLogController $controller;

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
        $this->auditLogger = new AuditLogger($this->db, $this->tenantContext, $this->auth);
        $this->inertia = Inertia::getInstance();

        $this->controller = new ActivityLogController(
            $this->auth,
            $this->auditLogger,
            $this->inertia,
            $this->session
        );
    }

    public function test_index_renders_activity_page_for_active_tenant(): void
    {
        // Setup Tenant 1 and User 1
        $userId = $this->db->insert('users', [
            'name' => 'Owner One',
            'email' => 'owner1@test.com',
            'password_hash' => 'hash',
            'is_superadmin' => 0,
        ]);
        $tenantId = $this->db->insert('tenants', [
            'name' => 'Workspace Alpha',
            'slug' => 'alpha',
            'plan' => 'pro',
        ]);
        $this->db->insert('tenant_user', [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'role' => 'owner',
        ]);

        $this->session->set('auth_user_id', (int) $userId);
        $this->session->set('auth_tenant_id', (int) $tenantId);
        $this->tenantContext->setTenantId((string) $tenantId);

        // Record logs for Tenant 1 and Tenant 2
        $this->auditLogger->log('project.created', 'Proyek Alfa Dibuat', tenantId: (int) $tenantId);
        $this->auditLogger->log('project.created', 'Proyek Beta Dibuat', tenantId: 999);

        $request = new Request('GET', '/activity', headers: ['x-inertia' => 'true']);
        $this->inertia->setRequest($request);
        $response = $this->controller->index($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);

        $this->assertSame('Activity/Index', $data['component']);
        $this->assertCount(1, $data['props']['logs']);
        $this->assertSame('Proyek Alfa Dibuat', $data['props']['logs'][0]['description']);
        $this->assertSame(1, $data['props']['totalLogs']);
    }

    public function test_superadmin_can_view_global_activity_logs(): void
    {
        // Setup Superadmin
        $userId = $this->db->insert('users', [
            'name' => 'Root Superadmin',
            'email' => 'root@test.com',
            'password_hash' => 'hash',
            'is_superadmin' => 1,
        ]);

        $this->session->set('auth_user_id', (int) $userId);

        $this->auditLogger->log('system.backup', 'Full Database Backup Selesai', tenantId: 1);
        $this->auditLogger->log('module.toggled', 'Modul CRM Diaktifkan', tenantId: 2);

        $request = new Request('GET', '/activity?scope=global', headers: ['x-inertia' => 'true'], queryParams: ['scope' => 'global']);
        $this->inertia->setRequest($request);
        $response = $this->controller->index($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);

        $this->assertSame('Activity/Index', $data['component']);
        $this->assertCount(2, $data['props']['logs']);
        $this->assertSame('global', $data['props']['activeScope']);
    }
}
