<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Backup\BackupManager;
use Core\Backup\BackupManagerInterface;
use Core\Backup\Drivers\SqliteBackupDriver;
use Core\Backup\Integrity\DatabaseIntegrityChecker;
use Core\Backup\SelfHealing\DatabaseSelfHealer;
use Core\Database\Connection;
use PHPUnit\Framework\TestCase;

class DatabaseSelfHealingTest extends TestCase
{
    private string $tempDir;
    private string $tempDbPath;
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aio_heal_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->tempDbPath = $this->tempDir . DIRECTORY_SEPARATOR . 'test_heal.sqlite';
        $this->db = new Connection("sqlite:{$this->tempDbPath}");

        // Create parent and child tables with foreign key
        $this->db->statement("
            CREATE TABLE parents (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL
            );
        ");
        $this->db->statement("
            CREATE TABLE children (
                id INTEGER PRIMARY KEY,
                parent_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                FOREIGN KEY (parent_id) REFERENCES parents(id) ON DELETE CASCADE
            );
        ");

        $this->db->statement("INSERT INTO parents (id, name) VALUES (1, 'Parent 1');");
        $this->db->statement("INSERT INTO children (id, parent_id, name) VALUES (1, 1, 'Child 1');");
    }

    protected function tearDown(): void
    {
        $this->db->close();
        $this->deleteRecursive($this->tempDir);
        parent::tearDown();
    }

    private function deleteRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function testIntegrityCheckerOnHealthyDatabase(): void
    {
        $checker = new DatabaseIntegrityChecker($this->db, $this->tempDbPath);
        $report = $checker->check();

        $this->assertTrue($report->isHealthy);
        $this->assertFalse($report->hasErrors());
        $this->assertSame('sqlite', $report->databaseType);
        $this->assertEmpty($report->integrityCheckErrors);
        $this->assertEmpty($report->foreignKeyViolations);
        $this->assertSame(2, $report->tableCount);
        $this->assertSame(2, $report->totalRecordCount);
        $this->assertStringContainsString('EXCELLENT', $report->getSummary());

        $diag = $report->diagnostics;
        $this->assertArrayHasKey('journal_mode', $diag);
        $this->assertArrayHasKey('page_size', $diag);
        $this->assertArrayHasKey('file_size_bytes', $diag);
    }

    public function testIntegrityCheckerDetectsForeignKeyViolation(): void
    {
        // Temporarily disable foreign keys to inject a dangling child record
        $this->db->statement("PRAGMA foreign_keys = OFF;");
        $this->db->statement("INSERT INTO children (id, parent_id, name) VALUES (2, 999, 'Orphan Child');");
        $this->db->statement("PRAGMA foreign_keys = ON;");

        $checker = new DatabaseIntegrityChecker($this->db, $this->tempDbPath);
        $report = $checker->check();

        $this->assertFalse($report->isHealthy);
        $this->assertTrue($report->hasErrors());
        $this->assertNotEmpty($report->foreignKeyViolations);
        $this->assertStringContainsString('WARNING', $report->getSummary());

        // Check details of FK violation
        $violation = $report->foreignKeyViolations[0];
        $this->assertSame('children', $violation['table']);
    }

    public function testDatabaseSelfHealerRoutines(): void
    {
        $healer = new DatabaseSelfHealer($this->db, $this->tempDbPath);
        $results = $healer->heal();

        $this->assertSame('sqlite', $results['database_type']);
        $this->assertSame('SUCCESS', $results['wal_checkpoint']);
        $this->assertSame('SUCCESS', $results['reindex']);
        $this->assertSame('SUCCESS', $results['vacuum']);
        $this->assertSame('SUCCESS', $results['optimize']);
        $this->assertTrue($results['is_healthy']);
        $this->assertGreaterThanOrEqual(0, $results['duration_ms']);
    }

    public function testBackupManagerVerifyAndHealDelegation(): void
    {
        $driver = new SqliteBackupDriver($this->db, $this->tempDbPath);
        $manager = new BackupManager(
            db: $this->db,
            driver: $driver,
            backupDirectory: $this->tempDir . DIRECTORY_SEPARATOR . 'backups'
        );

        $report = $manager->verify();
        $this->assertTrue($report->isHealthy);

        $healResults = $manager->heal();
        $this->assertTrue($healResults['is_healthy']);
    }

    public function testGlobalBackupHelper(): void
    {
        $container = \Core\Container::getInstance();
        $driver = new SqliteBackupDriver($this->db, $this->tempDbPath);
        $manager = new BackupManager(
            db: $this->db,
            driver: $driver,
            backupDirectory: $this->tempDir . DIRECTORY_SEPARATOR . 'backups'
        );
        $container->instance(BackupManagerInterface::class, $manager);

        if (function_exists('backup')) {
            $resolved = backup();
            $this->assertInstanceOf(BackupManagerInterface::class, $resolved);
        } else {
            $this->markTestSkipped('Global backup() helper is not loaded.');
        }
    }
}
