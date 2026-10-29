<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Console\Commands\QueueWorkCommand;
use Core\Console\Kernel;
use Core\Container;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use Core\Database\TenantScope;
use Core\Queue\JobInterface;
use Core\Queue\QueueManager;
use PHPUnit\Framework\TestCase;

// Dummy Job that writes data using TenantScope
class ScopedWorkerJob implements JobInterface
{
    public function handle(array $data, Container $container): void
    {
        /** @var TenantScope $scope */
        $scope = $container->get(TenantScope::class);

        // This call will throw RuntimeException if tenant context is missing!
        $scope->insert('tasks', [
            'project_id' => $data['project_id'],
            'title' => $data['title'],
            'is_completed' => 0,
        ]);
    }
}

class QueueTenantPropagationTest extends TestCase
{
    private Connection $db;
    private Container $container;
    private TenantContext $context;
    private TenantScope $scope;
    private QueueManager $queue;
    private Kernel $kernel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new Connection('sqlite::memory:');
        $migrator = new Migrator($this->db);
        $migrator->runMigrationsFrom(dirname(__DIR__, 2) . '/database/migrations');
        $migrator->runMigrationsFrom(dirname(__DIR__, 2) . '/app/Modules/Projects/Database/Migrations', 'projects');

        $this->container = new Container();
        $this->container->singleton(Connection::class, fn() => $this->db);

        $this->context = new TenantContext();
        TenantContext::setInstance($this->context);
        $this->container->singleton(TenantContext::class, fn() => $this->context);

        $this->scope = new TenantScope($this->context, $this->db);
        $this->container->singleton(TenantScope::class, fn() => $this->scope);

        $this->queue = new QueueManager($this->db);
        $this->container->singleton(QueueManager::class, fn() => $this->queue);

        $this->container->singleton(ScopedWorkerJob::class, fn() => new ScopedWorkerJob());

        $this->kernel = new Kernel($this->container);
        $this->kernel->register(new QueueWorkCommand());
    }

    public function test_queue_push_auto_captures_active_tenant(): void
    {
        $this->context->setTenantId("42");
        $jobId = $this->queue->push(ScopedWorkerJob::class, ['title' => 'Auto Tenant Job', 'project_id' => 1]);

        $job = $this->queue->pop('default');
        $this->assertNotNull($job);
        $this->assertSame(42, $job->getTenantId(), "Job harus otomatis menangkap tenant ID aktif saat di-push");
    }

    public function test_queue_worker_restores_tenant_context_during_execution(): void
    {
        // 1. Setup tenant & project in database
        $this->db->statement("INSERT INTO tenants (id, name, slug) VALUES (10, 'Tenant Ten', 'tenant-ten')");
        $this->db->statement("INSERT INTO users (id, name, email, password_hash) VALUES (1, 'User 1', 'u1@test.com', 'h')");
        $this->db->statement("INSERT INTO projects (id, tenant_id, user_id, name) VALUES (100, 10, 1, 'Project 100')");

        // 2. Push job for Tenant 10
        $this->queue->push(ScopedWorkerJob::class, [
            'project_id' => 100,
            'title' => 'Async Task from Tenant 10',
        ], 10);

        // 3. Ensure outside context is currently null
        $this->context->setTenantId(null);
        $this->assertNull($this->context->getTenantId());

        // 4. Run worker with --once
        ob_start();
        $exitCode = $this->kernel->handle(['aio', 'queue:work', '--once']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString("Selesai diproses", $output);

        // 5. Verify outside context is reset to null immediately after worker finished (no leakage)
        $this->assertNull($this->context->getTenantId());

        // 6. Verify the task was inserted under tenant_id = 10
        $this->context->setTenantId("10");
        $tasks = $this->scope->selectAll('tasks');
        $this->assertCount(1, $tasks);
        $this->assertSame('Async Task from Tenant 10', $tasks[0]['title']);
        $this->assertSame(10, (int) $tasks[0]['tenant_id']);
    }
}
