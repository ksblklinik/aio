<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Database\Connection;
use Core\Database\TenantContext;
use Core\Database\TenantScope;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SoftDeletesTenantScopeTest extends TestCase
{
    private Connection $db;
    private TenantContext $context;
    private TenantScope $scope;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new Connection('sqlite::memory:');
        $this->context = new TenantContext();
        $this->context->setTenantId('1', ['name' => 'Acme Corp']);
        $this->scope = new TenantScope($this->context, $this->db);

        // Setup test tables
        $this->db->statement("
            CREATE TABLE soft_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                deleted_at DATETIME NULL
            )
        ");

        $this->db->statement("
            CREATE TABLE non_soft_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                title TEXT NOT NULL
            )
        ");
    }

    public function test_soft_delete_and_automatic_filtering(): void
    {
        $id1 = $this->scope->insert('soft_items', ['name' => 'Item 1']);
        $id2 = $this->scope->insert('soft_items', ['name' => 'Item 2']);
        $id3 = $this->scope->insert('soft_items', ['name' => 'Item 3']);

        // Default: 3 items
        $this->assertCount(3, $this->scope->selectAll('soft_items'));
        $this->assertSame(3, $this->scope->count('soft_items'));

        // Soft delete item 2
        $affected = $this->scope->softDelete('soft_items', 'id = ?', [$id2]);
        $this->assertSame(1, $affected);

        // Verify item 2 has deleted_at set in raw DB
        $rawItem2 = $this->db->selectOne("SELECT deleted_at FROM soft_items WHERE id = ?", [$id2]);
        $this->assertNotNull($rawItem2['deleted_at']);

        // Default query excludes soft deleted item
        $items = $this->scope->selectAll('soft_items');
        $this->assertCount(2, $items);
        $this->assertSame('Item 1', $items[0]['name']);
        $this->assertSame('Item 3', $items[1]['name']);

        // selectOne excludes soft deleted item
        $this->assertNull($this->scope->selectOne('soft_items', 'id = ?', [$id2]));
        $this->assertNotNull($this->scope->selectOne('soft_items', 'id = ?', [$id1]));

        // count excludes soft deleted item
        $this->assertSame(2, $this->scope->count('soft_items'));
    }

    public function test_with_trashed_and_only_trashed_modes(): void
    {
        $id1 = $this->scope->insert('soft_items', ['name' => 'Active A']);
        $id2 = $this->scope->insert('soft_items', ['name' => 'Active B']);
        $this->scope->softDelete('soft_items', 'id = ?', [$id2]);

        // withTrashed returns both active and trashed
        $all = $this->scope->withTrashed()->selectAll('soft_items');
        $this->assertCount(2, $all);
        $this->assertSame(2, $this->scope->withTrashed()->count('soft_items'));

        // onlyTrashed returns only soft-deleted items
        $trashed = $this->scope->onlyTrashed()->selectAll('soft_items');
        $this->assertCount(1, $trashed);
        $this->assertSame('Active B', $trashed[0]['name']);
        $this->assertSame(1, $this->scope->onlyTrashed()->count('soft_items'));

        // Pagination with soft deletes
        $paginated = $this->scope->paginate('soft_items', 10, 1);
        $this->assertSame(1, $paginated['total']);

        $paginatedAll = $this->scope->withTrashed()->paginate('soft_items', 10, 1);
        $this->assertSame(2, $paginatedAll['total']);
    }

    public function test_restore_soft_deleted_record(): void
    {
        $id = $this->scope->insert('soft_items', ['name' => 'Restore Me']);
        $this->scope->softDelete('soft_items', 'id = ?', [$id]);
        $this->assertCount(0, $this->scope->selectAll('soft_items'));

        $restored = $this->scope->restore('soft_items', 'id = ?', [$id]);
        $this->assertSame(1, $restored);

        $active = $this->scope->selectAll('soft_items');
        $this->assertCount(1, $active);
        $this->assertNull($active[0]['deleted_at']);
    }

    public function test_force_delete_removes_permanently(): void
    {
        $id = $this->scope->insert('soft_items', ['name' => 'Permanent Delete']);
        $this->scope->softDelete('soft_items', 'id = ?', [$id]);

        $affected = $this->scope->forceDelete('soft_items', 'id = ?', [$id]);
        $this->assertSame(1, $affected);

        // Record completely removed from database
        $raw = $this->db->selectOne("SELECT * FROM soft_items WHERE id = ?", [$id]);
        $this->assertNull($raw);
    }

    public function test_non_soft_delete_table_behaves_normally(): void
    {
        $this->scope->insert('non_soft_items', ['title' => 'Doc 1']);
        $this->scope->insert('non_soft_items', ['title' => 'Doc 2']);

        $items = $this->scope->selectAll('non_soft_items');
        $this->assertCount(2, $items);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("tidak mendukung soft deletes");
        $this->scope->softDelete('non_soft_items', 'id = ?', [1]);
    }
}
