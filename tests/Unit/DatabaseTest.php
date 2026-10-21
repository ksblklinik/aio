<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use Core\Database\TenantScope;
use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase
{
    private Connection $db;
    private TenantContext $tenantContext;
    private TenantScope $tenantScope;

    protected function setUp(): void
    {
        $this->db = new Connection('sqlite::memory:');
        $this->tenantContext = new TenantContext();
        $this->tenantScope = new TenantScope($this->tenantContext, $this->db);

        // Setup test schema
        $this->db->statement("
            CREATE TABLE projects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id TEXT NOT NULL,
                name TEXT NOT NULL
            );
        ");
    }

    public function test_tenant_context_stores_active_tenant(): void
    {
        $this->assertFalse($this->tenantContext->hasTenant());
        $this->tenantContext->setTenantId('tenant-alpha');
        $this->assertTrue($this->tenantContext->hasTenant());
        $this->assertSame('tenant-alpha', $this->tenantContext->getTenantId());
    }

    public function test_tenant_scope_inserts_with_tenant_id_automatically(): void
    {
        $this->tenantContext->setTenantId('tenant-101');

        $id = $this->tenantScope->insert('projects', ['name' => 'Project 1']);
        $this->assertGreaterThan(0, (int) $id);

        $row = $this->db->selectOne("SELECT * FROM projects WHERE id = ?", [$id]);
        $this->assertSame('tenant-101', $row['tenant_id']);
        $this->assertSame('Project 1', $row['name']);
    }

    public function test_tenant_scope_filters_queries_to_active_tenant_only(): void
    {
        // Seed projects for two different tenants
        $this->db->statement("INSERT INTO projects (tenant_id, name) VALUES ('tenant-A', 'Alpha Project')");
        $this->db->statement("INSERT INTO projects (tenant_id, name) VALUES ('tenant-B', 'Beta Project')");

        // Tenant A context
        $this->tenantContext->setTenantId('tenant-A');
        $resultsA = $this->tenantScope->selectAll('projects');
        $this->assertCount(1, $resultsA);
        $this->assertSame('Alpha Project', $resultsA[0]['name']);

        // Tenant B context
        $this->tenantContext->setTenantId('tenant-B');
        $resultsB = $this->tenantScope->selectAll('projects');
        $this->assertCount(1, $resultsB);
        $this->assertSame('Beta Project', $resultsB[0]['name']);
    }

    public function test_migrator_runs_migrations_and_tracks_batch(): void
    {
        $migrator = new Migrator($this->db);
        $migrator->createMigrationsTable();

        $migrationSql = "CREATE TABLE dummy_items (id INTEGER PRIMARY KEY, title TEXT);";
        $migrator->runMigration('2026_01_01_000001_create_dummy_items', $migrationSql);

        $this->assertTrue($migrator->hasRun('2026_01_01_000001_create_dummy_items'));
        $this->assertFalse($migrator->hasRun('2026_01_01_000002_not_run'));
    }

    public function test_migrator_runs_migrations_from_directory(): void
    {
        $migrator = new Migrator($this->db);
        $tempDir = sys_get_temp_dir() . '/aio_migrations_' . uniqid();
        mkdir($tempDir);

        file_put_contents($tempDir . '/2026_01_01_000001_first.sql', 'CREATE TABLE tbl_first (id INTEGER PRIMARY KEY);');
        file_put_contents($tempDir . '/2026_01_01_000002_second.sql', 'CREATE TABLE tbl_second (id INTEGER PRIMARY KEY);');

        $executed = $migrator->runMigrationsFrom($tempDir);
        $this->assertCount(2, $executed);
        $this->assertSame(['2026_01_01_000001_first', '2026_01_01_000002_second'], $executed);

        // Running again should execute 0 new migrations
        $executedAgain = $migrator->runMigrationsFrom($tempDir);
        $this->assertCount(0, $executedAgain);

        // Cleanup
        unlink($tempDir . '/2026_01_01_000001_first.sql');
        unlink($tempDir . '/2026_01_01_000002_second.sql');
        rmdir($tempDir);
    }
}
