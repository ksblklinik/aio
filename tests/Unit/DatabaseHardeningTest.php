<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Database\Connection;
use Core\Database\TenantContext;
use Core\Database\TenantScope;
use Exception;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DatabaseHardeningTest extends TestCase
{
    private Connection $db;
    private TenantContext $context;
    private TenantScope $scope;
    private string $tempDbFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDbFile = sys_get_temp_dir() . '/test_db_' . uniqid() . '.sqlite';
        $this->db = new Connection("sqlite:{$this->tempDbFile}");

        // Create sample tables with Foreign Keys and Tenant ID
        $this->db->statement("
            CREATE TABLE parents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL
            );
        ");

        $this->db->statement("
            CREATE TABLE children (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                FOREIGN KEY (parent_id) REFERENCES parents(id) ON DELETE CASCADE
            );
        ");

        $this->db->statement("
            CREATE TABLE items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'active'
            );
        ");

        $this->context = new TenantContext();
        $this->scope = new TenantScope($this->context, $this->db);
    }

    protected function tearDown(): void
    {
        $this->db->close();
        if (file_exists($this->tempDbFile)) {
            unlink($this->tempDbFile);
        }
        parent::tearDown();
    }

    public function test_sqlite_pragmas_are_enabled(): void
    {
        $fk = $this->db->selectOne("PRAGMA foreign_keys");
        $this->assertSame(1, (int) ($fk['foreign_keys'] ?? 0), "Foreign keys harus aktif (ON)");

        $timeout = $this->db->selectOne("PRAGMA busy_timeout");
        $this->assertGreaterThanOrEqual(5000, (int) ($timeout['timeout'] ?? 0), "Busy timeout harus >= 5000ms");

        $journal = $this->db->selectOne("PRAGMA journal_mode");
        $this->assertSame('wal', strtolower((string) ($journal['journal_mode'] ?? '')), "Journal mode harus WAL");
    }

    public function test_foreign_keys_cascade_delete_works(): void
    {
        $parentId = $this->db->insert('parents', ['name' => 'Parent 1']);
        $this->db->insert('children', ['parent_id' => $parentId, 'name' => 'Child 1']);

        $this->assertSame(1, (int) $this->db->selectOne("SELECT COUNT(*) as c FROM children")['c']);

        // Delete parent, cascade should delete child automatically
        $this->db->statement("DELETE FROM parents WHERE id = ?", [$parentId]);
        $this->assertSame(0, (int) $this->db->selectOne("SELECT COUNT(*) as c FROM children")['c']);
    }

    public function test_transaction_rolls_back_on_any_throwable(): void
    {
        try {
            $this->db->transaction(function (Connection $conn) {
                $conn->statement("INSERT INTO parents (name) VALUES ('Rollback Test')");
                throw new Exception("Simulated generic exception");
            });
        } catch (Exception $e) {
            $this->assertSame("Simulated generic exception", $e->getMessage());
        }

        $row = $this->db->selectOne("SELECT COUNT(*) as c FROM parents WHERE name = 'Rollback Test'");
        $this->assertSame(0, (int) $row['c'], "Data harus di-rollback saat terjadi generic Throwable");
    }

    public function test_nested_transaction_savepoints_commit_and_rollback(): void
    {
        // 1. Outer commit, inner rollback
        $this->db->transaction(function (Connection $conn) {
            $conn->statement("INSERT INTO parents (name) VALUES ('Outer Parent')");

            try {
                $conn->transaction(function (Connection $innerConn) {
                    $innerConn->statement("INSERT INTO parents (name) VALUES ('Inner Parent Failed')");
                    throw new RuntimeException("Inner failed");
                });
            } catch (RuntimeException $e) {
                // Ignore inner error
            }
        });

        $outer = $this->db->selectOne("SELECT COUNT(*) as c FROM parents WHERE name = 'Outer Parent'");
        $inner = $this->db->selectOne("SELECT COUNT(*) as c FROM parents WHERE name = 'Inner Parent Failed'");

        $this->assertSame(1, (int) $outer['c'], "Outer transaction harus tetap ter-commit");
        $this->assertSame(0, (int) $inner['c'], "Inner transaction savepoint harus ter-rollback");
    }

    public function test_connection_update_and_delete_methods(): void
    {
        $id = $this->db->insert('parents', ['name' => 'Initial Name']);
        
        $affected = $this->db->update('parents', ['name' => 'Updated Name'], 'id = ?', [$id]);
        $this->assertSame(1, $affected);

        $row = $this->db->selectOne("SELECT name FROM parents WHERE id = ?", [$id]);
        $this->assertSame('Updated Name', $row['name']);

        $deleted = $this->db->delete('parents', 'id = ?', [$id]);
        $this->assertSame(1, $deleted);

        $rowAfter = $this->db->selectOne("SELECT * FROM parents WHERE id = ?", [$id]);
        $this->assertNull($rowAfter);
    }

    public function test_tenant_scope_crud_and_isolation(): void
    {
        // Insert data for Tenant 10
        $this->context->setTenantId("10");
        $id1 = $this->scope->insert('items', ['title' => 'T10 Item 1', 'status' => 'pending']);
        $id2 = $this->scope->insert('items', ['title' => 'T10 Item 2', 'status' => 'pending']);

        // Insert data for Tenant 20
        $this->context->setTenantId("20");
        $id3 = $this->scope->insert('items', ['title' => 'T20 Item 1', 'status' => 'pending']);

        // Test Count & Exists on Tenant 10
        $this->context->setTenantId("10");
        $this->assertSame(2, $this->scope->count('items'));
        $this->assertSame(2, $this->scope->count('items', 'status = ?', ['pending']));
        $this->assertTrue($this->scope->exists('items', 'id = ?', [$id1]));
        $this->assertFalse($this->scope->exists('items', 'id = ?', [$id3])); // Cannot see Tenant 20

        // Test Update scoped to Tenant 10
        $affected = $this->scope->update('items', ['status' => 'completed'], 'id = ?', [$id1]);
        $this->assertSame(1, $affected);

        // Attempting to update Tenant 20 data while scoped in Tenant 10 must affect 0 rows
        $hackedUpdate = $this->scope->update('items', ['status' => 'hacked'], 'id = ?', [$id3]);
        $this->assertSame(0, $hackedUpdate);

        // Test Delete scoped to Tenant 10
        $deleted = $this->scope->delete('items', 'id = ?', [$id2]);
        $this->assertSame(1, $deleted);
        $this->assertSame(1, $this->scope->count('items'));

        // Attempting to delete Tenant 20 data while scoped in Tenant 10 must affect 0 rows
        $hackedDelete = $this->scope->delete('items', 'id = ?', [$id3]);
        $this->assertSame(0, $hackedDelete);

        // Switch to Tenant 20: verify item is intact
        $this->context->setTenantId("20");
        $this->assertSame(1, $this->scope->count('items'));
        $itemT20 = $this->scope->selectOne('items', 'id = ?', [$id3]);
        $this->assertSame('pending', $itemT20['status']);
    }

    public function test_tenant_scope_pagination(): void
    {
        $this->context->setTenantId("10");
        for ($i = 1; $i <= 25; $i++) {
            $this->scope->insert('items', ['title' => "Item {$i}", 'status' => 'active']);
        }

        // Page 1 with 10 per page
        $page1 = $this->scope->paginate('items', 10, 1, '', [], 'id ASC');
        $this->assertCount(10, $page1['data']);
        $this->assertSame(25, $page1['total']);
        $this->assertSame(10, $page1['per_page']);
        $this->assertSame(1, $page1['current_page']);
        $this->assertSame(3, $page1['last_page']);
        $this->assertSame('Item 1', $page1['data'][0]['title']);

        // Page 3 (remaining 5)
        $page3 = $this->scope->paginate('items', 10, 3, '', [], 'id ASC');
        $this->assertCount(5, $page3['data']);
        $this->assertSame('Item 21', $page3['data'][0]['title']);
    }
}
