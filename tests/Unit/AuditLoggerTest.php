<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Workspaces\Models\User;
use Core\Audit\AuditLogger;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use Core\Http\Request;
use Core\Http\Session;
use PHPUnit\Framework\TestCase;

class AuditLoggerTest extends TestCase
{
    private Connection $db;
    private TenantContext $tenantContext;
    private AuthService $authService;
    private Session $session;

    protected function setUp(): void
    {
        $this->db = new Connection('sqlite::memory:');
        $migrator = new Migrator($this->db);
        $migrator->runMigrationsFrom(dirname(__DIR__, 2) . '/database/migrations');

        $this->tenantContext = new TenantContext();
        $this->session = new Session();
        $this->session->startArrayDriver();
        $this->authService = new AuthService($this->db, $this->session, $this->tenantContext);
    }

    public function test_audit_logger_records_log_with_explicit_data(): void
    {
        $tenantId = (int) $this->db->insert('tenants', ['name' => 'Acme', 'slug' => 'acme', 'plan' => 'pro']);
        $userId = (int) $this->db->insert('users', ['name' => 'John Doe', 'email' => 'john@test.com', 'password_hash' => 'hash']);

        $logger = new AuditLogger($this->db);

        $logId = $logger->log(
            action: 'project.created',
            description: 'Membuat proyek baru: Website Redesign',
            entityType: 'project',
            entityId: '42',
            properties: ['name' => 'Website Redesign', 'status' => 'planned'],
            tenantId: $tenantId,
            userId: $userId,
            userName: 'John Doe'
        );

        $this->assertGreaterThan(0, $logId);

        $logs = $logger->getForTenant($tenantId);
        $this->assertCount(1, $logs);
        $this->assertSame('project.created', $logs[0]['action']);
        $this->assertSame('Membuat proyek baru: Website Redesign', $logs[0]['description']);
        $this->assertSame('project', $logs[0]['entity_type']);
        $this->assertSame('42', $logs[0]['entity_id']);
        $this->assertSame('John Doe', $logs[0]['user_name']);
        $this->assertSame(['name' => 'Website Redesign', 'status' => 'planned'], $logs[0]['properties']);
        $this->assertSame('emerald', $logs[0]['badge']['color']);
    }

    public function test_audit_logger_auto_detects_tenant_and_authenticated_user(): void
    {
        // Insert user and tenant
        $userId = $this->db->insert('users', [
            'name' => 'Alice Admin',
            'email' => 'alice@example.com',
            'password_hash' => password_hash('password', PASSWORD_DEFAULT),
            'is_superadmin' => 0,
        ]);

        $tenantId = $this->db->insert('tenants', [
            'name' => 'Starlight Corp',
            'slug' => 'starlight',
            'plan' => 'pro',
        ]);

        $this->tenantContext->setTenantId((string) $tenantId);
        $this->session->set('auth_user_id', (int) $userId);
        $this->session->set('auth_tenant_id', (int) $tenantId);

        $request = new Request(
            method: 'POST',
            uri: '/projects',
            headers: ['user-agent' => 'PHPUnit/TestBrowser'],
            serverParams: ['REMOTE_ADDR' => '192.168.1.100']
        );

        $logger = new AuditLogger($this->db, $this->tenantContext, $this->authService, $request);

        $logId = $logger->log(
            action: 'auth.login',
            description: 'Pengguna Alice Admin berhasil masuk sistem'
        );

        $this->assertGreaterThan(0, $logId);

        $logs = $logger->getForTenant((int) $tenantId);
        $this->assertCount(1, $logs);
        $this->assertSame((int) $tenantId, (int) $logs[0]['tenant_id']);
        $this->assertSame((int) $userId, (int) $logs[0]['user_id']);
        $this->assertSame('Alice Admin', $logs[0]['user_name']);
        $this->assertSame('192.168.1.100', $logs[0]['ip_address']);
        $this->assertSame('PHPUnit/TestBrowser', $logs[0]['user_agent']);
        $this->assertSame('indigo', $logs[0]['badge']['color']);
    }

    public function test_audit_logger_tenant_isolation(): void
    {
        $t1 = (int) $this->db->insert('tenants', ['name' => 'T1', 'slug' => 't1', 'plan' => 'pro']);
        $t2 = (int) $this->db->insert('tenants', ['name' => 'T2', 'slug' => 't2', 'plan' => 'pro']);

        $logger = new AuditLogger($this->db);

        // Log untuk Tenant 1
        $logger->log('task.created', 'Task for tenant 1', tenantId: $t1);
        $logger->log('task.completed', 'Task completed for tenant 1', tenantId: $t1);

        // Log untuk Tenant 2
        $logger->log('task.created', 'Task for tenant 2', tenantId: $t2);

        $this->assertSame(2, $logger->countForTenant($t1));
        $this->assertSame(1, $logger->countForTenant($t2));
        $this->assertSame(0, $logger->countForTenant(999));

        $tenant1Logs = $logger->getForTenant($t1);
        $this->assertCount(2, $tenant1Logs);

        $tenant2Logs = $logger->getForTenant($t2);
        $this->assertCount(1, $tenant2Logs);
    }

    public function test_global_audit_helper(): void
    {
        $container = \Core\Container::getInstance();
        $logger = new AuditLogger($this->db, $this->tenantContext, $this->authService);
        $container->instance(AuditLogger::class, $logger);

        // audit() without params returns AuditLogger instance
        $this->assertInstanceOf(AuditLogger::class, audit());

        // audit() with params logs and returns ID
        $tId = (int) $this->db->insert('tenants', ['name' => 'Helper Co', 'slug' => 'helper', 'plan' => 'starter']);
        $logId = audit('system.helper_test', 'Testing global audit helper', tenantId: $tId);

        $this->assertGreaterThan(0, $logId);
        $logs = audit()->getForTenant($tId);
        $this->assertCount(1, $logs);
        $this->assertSame('system.helper_test', $logs[0]['action']);
        $this->assertSame('Testing global audit helper', $logs[0]['description']);
    }
}
