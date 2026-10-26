<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use Core\Database\TenantScope;
use Core\Pagination\LengthAwarePaginator;
use PHPUnit\Framework\TestCase;

class UniversalPaginationTest extends TestCase
{
    private Connection $db;
    private Migrator $migrator;
    private TenantContext $tenantContext;
    private TenantScope $scope;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new Connection('sqlite::memory:');
        $this->migrator = new Migrator($this->db);
        $this->migrator->runMigrationsFrom(__DIR__ . '/../../database/migrations');

        $this->tenantContext = TenantContext::getInstance();
        $this->tenantContext->clear();

        $this->scope = new TenantScope($this->tenantContext, $this->db);
    }

    protected function tearDown(): void
    {
        $this->tenantContext->clear();
        parent::tearDown();
    }

    public function test_length_aware_paginator_calculations_and_links(): void
    {
        $items = range(1, 10);
        $total = 45;
        $perPage = 10;
        $currentPage = 2;

        $paginator = new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $currentPage,
            '/projects',
            ['sort' => 'latest']
        );

        $this->assertSame(5, $paginator->lastPage());
        $this->assertSame(11, $paginator->from());
        $this->assertSame(20, $paginator->to());
        $this->assertTrue($paginator->hasMorePages());

        $links = $paginator->links();
        // 1 prev + 5 pages + 1 next = 7 links
        $this->assertCount(7, $links);

        // Previous link pointing to page 1
        $this->assertStringContainsString('page=1', (string) $links[0]['url']);
        $this->assertStringContainsString('sort=latest', (string) $links[0]['url']);

        // Active page 2
        $this->assertTrue($links[2]['active']);
        $this->assertSame('2', $links[2]['label']);

        // Next link pointing to page 3
        $this->assertStringContainsString('page=3', (string) $links[6]['url']);
    }

    public function test_tenant_scope_paginate_integration_with_links(): void
    {
        // Buat tabel sample untuk testing
        $this->db->statement("
            CREATE TABLE IF NOT EXISTS test_records (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                name TEXT NOT NULL
            )
        ");

        $tenantA = 1;
        $tenantB = 2;

        // Insert 25 records for tenant A, and 10 for tenant B
        for ($i = 1; $i <= 25; $i++) {
            $this->db->insert('test_records', [
                'tenant_id' => $tenantA,
                'name' => "Record A-{$i}",
            ]);
        }
        for ($i = 1; $i <= 10; $i++) {
            $this->db->insert('test_records', [
                'tenant_id' => $tenantB,
                'name' => "Record B-{$i}",
            ]);
        }

        // Set tenant A context
        $this->tenantContext->setTenantId((string) $tenantA);

        $page1 = $this->scope->paginate('test_records', 10, 1, '', [], 'id ASC');
        $this->assertSame(25, $page1['total']);
        $this->assertSame(10, $page1['per_page']);
        $this->assertSame(1, $page1['current_page']);
        $this->assertSame(3, $page1['last_page']);
        $this->assertSame(1, $page1['from']);
        $this->assertSame(10, $page1['to']);
        $this->assertTrue($page1['has_more']);
        $this->assertCount(10, $page1['data']);
        $this->assertCount(5, $page1['links']); // prev, 1, 2, 3, next

        // Page 3 (last page with remaining 5 items)
        $page3 = $this->scope->paginate('test_records', 10, 3, '', [], 'id ASC');
        $this->assertCount(5, $page3['data']);
        $this->assertSame(21, $page3['from']);
        $this->assertSame(25, $page3['to']);
        $this->assertFalse($page3['has_more']);

        // Verify isolation: tenant B has only 10 items
        $this->tenantContext->setTenantId((string) $tenantB);
        $pageB = $this->scope->paginate('test_records', 10, 1);
        $this->assertSame(10, $pageB['total']);
        $this->assertSame(1, $pageB['last_page']);
    }
}
