<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Console\Commands\MigrateCommand;
use Core\Console\Commands\MigrateRollbackCommand;
use Core\Console\Commands\SeedCommand;
use Core\Console\Kernel;
use Core\Container;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\Seeder;
use Core\Modules\ModuleManager;
use PHPUnit\Framework\TestCase;

class TestDummySeeder extends Seeder
{
    public static bool $executed = false;

    public function run(): void
    {
        self::$executed = true;
        $this->db->statement("INSERT INTO dummy_posts (title) VALUES ('Seeded Post')");
    }
}

class ModularMigrationAndRollbackTest extends TestCase
{
    private Connection $db;
    private Migrator $migrator;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        TestDummySeeder::$executed = false;

        $this->db = new Connection('sqlite::memory:');
        $this->tempDir = sys_get_temp_dir() . '/aio_mig_test_' . uniqid();
        mkdir($this->tempDir . '/core_migrations', 0755, true);
        mkdir($this->tempDir . '/modules/Blog/Database/Migrations', 0755, true);
        mkdir($this->tempDir . '/modules/Blog/Database/Seeders', 0755, true);

        // module.json for Blog
        file_put_contents($this->tempDir . '/modules/Blog/module.json', json_encode([
            'id' => 'blog',
            'name' => 'Blog Module',
            'is_active' => true,
        ]));

        $moduleManager = new ModuleManager($this->db->getPdo(), $this->tempDir . '/modules');
        $this->migrator = new Migrator($this->db, $this->tempDir . '/core_migrations', $moduleManager);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $p = $dir . '/' . $file;
            is_dir($p) ? $this->removeDirectory($p) : unlink($p);
        }
        rmdir($dir);
    }

    public function test_migrator_parses_up_and_down_markers_correctly(): void
    {
        $sqlWithMarkers = <<<SQL
-- @up
CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT);
CREATE INDEX idx_title ON posts(title);

-- @down
DROP INDEX IF EXISTS idx_title;
DROP TABLE IF EXISTS posts;
SQL;

        $parsed = Migrator::parseMigrationFile($sqlWithMarkers);
        $this->assertStringContainsString('CREATE TABLE posts', $parsed['up']);
        $this->assertStringContainsString('CREATE INDEX idx_title', $parsed['up']);
        $this->assertStringContainsString('DROP INDEX IF EXISTS idx_title', $parsed['down']);
        $this->assertStringContainsString('DROP TABLE IF EXISTS posts', $parsed['down']);

        // Plain SQL without markers
        $plainSql = "CREATE TABLE tags (id INTEGER PRIMARY KEY);";
        $parsedPlain = Migrator::parseMigrationFile($plainSql);
        $this->assertSame($plainSql, $parsedPlain['up']);
        $this->assertSame('', $parsedPlain['down']);
    }

    public function test_migrator_creates_table_with_module_and_down_sql_columns(): void
    {
        $this->migrator->createMigrationsTable();

        $cols = $this->db->select("PRAGMA table_info(migrations)");
        $colNames = array_column($cols, 'name');

        $this->assertContains('id', $colNames);
        $this->assertContains('migration', $colNames);
        $this->assertContains('batch', $colNames);
        $this->assertContains('module', $colNames);
        $this->assertContains('down_sql', $colNames);
        $this->assertContains('created_at', $colNames);
    }

    public function test_migrator_runs_modular_migrations_and_supports_rollback(): void
    {
        // 1. Core migration
        $coreFile = $this->tempDir . '/core_migrations/001_create_dummy_core.sql';
        file_put_contents($coreFile, <<<SQL
-- @up
CREATE TABLE dummy_core (id INTEGER PRIMARY KEY);
-- @down
DROP TABLE IF EXISTS dummy_core;
SQL);

        // 2. Blog module migration
        $blogFile = $this->tempDir . '/modules/Blog/Database/Migrations/001_create_dummy_posts.sql';
        file_put_contents($blogFile, <<<SQL
-- @up
CREATE TABLE dummy_posts (id INTEGER PRIMARY KEY, title TEXT);
-- @down
DROP TABLE IF EXISTS dummy_posts;
SQL);

        // Run all migrations
        $executed = $this->migrator->runAllMigrations();
        $this->assertCount(2, $executed);

        // Tables exist
        $this->assertTrue($this->db->tableExists('dummy_core'));
        $this->assertTrue($this->db->tableExists('dummy_posts'));

        // Check records in migrations table
        $rows = $this->db->select("SELECT * FROM migrations ORDER BY id ASC");
        $this->assertCount(2, $rows);
        $this->assertSame('core', $rows[0]['module']);
        $this->assertSame('blog', $rows[1]['module']);
        $this->assertStringContainsString('DROP TABLE IF EXISTS dummy_posts', $rows[1]['down_sql']);

        // Rollback only blog module
        $rolledBack = $this->migrator->rollbackModule('blog');
        $this->assertCount(1, $rolledBack);
        $this->assertSame('001_create_dummy_posts', $rolledBack[0]);

        // Blog table should be dropped, core table remains
        $this->assertFalse($this->db->tableExists('dummy_posts'));
        $this->assertTrue($this->db->tableExists('dummy_core'));

        // Rollback core / last batch
        $rolledBackCore = $this->migrator->rollbackLastBatch();
        $this->assertCount(1, $rolledBackCore);
        $this->assertSame('001_create_dummy_core', $rolledBackCore[0]);
        $this->assertFalse($this->db->tableExists('dummy_core'));
    }

    public function test_seeder_executes_and_inserts_data(): void
    {
        $this->db->statement("CREATE TABLE dummy_posts (id INTEGER PRIMARY KEY, title TEXT)");

        $seeder = new TestDummySeeder($this->db);
        $seeder->run();

        $this->assertTrue(TestDummySeeder::$executed);
        $row = $this->db->selectOne("SELECT title FROM dummy_posts");
        $this->assertSame('Seeded Post', $row['title']);
    }

    public function test_migrate_and_rollback_commands_cli(): void
    {
        $container = new Container();
        $container->singleton(Connection::class, fn() => $this->db);
        $container->singleton(Migrator::class, fn() => $this->migrator);
        $kernel = new Kernel($container);

        $kernel->register(new MigrateCommand())
               ->register(new MigrateRollbackCommand())
               ->register(new SeedCommand());

        $commands = $kernel->getCommands();
        $this->assertArrayHasKey('migrate', $commands);
        $this->assertArrayHasKey('migrate:rollback', $commands);
        $this->assertArrayHasKey('db:seed', $commands);
    }
}
