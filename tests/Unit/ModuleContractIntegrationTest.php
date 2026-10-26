<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Auth\Gate;
use Core\Container;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Events\EventDispatcher;
use Core\Foundation\Application;
use Core\Http\Request;
use Core\Modules\ModuleManager;
use Core\Router;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\Modules\SampleContractModule\SampleContractServiceProvider;
use Tests\Fixtures\Modules\SampleContractModule\Database\Seeders\SampleContractSeeder;

class ModuleContractIntegrationTest extends TestCase
{
    private Connection $db;
    private Application $app;
    private string $tempModulesDir;
    private string $tempPagesDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new Connection('sqlite::memory:');
        $this->app = new Application(dirname(__DIR__, 2));
        $this->app->getContainer()->instance(Connection::class, $this->db);
        $this->app->getContainer()->instance(PDO::class, $this->db->getPdo());

        $this->tempModulesDir = sys_get_temp_dir() . '/aio_contract_modules_' . bin2hex(random_bytes(6));
        $this->tempPagesDir = sys_get_temp_dir() . '/aio_contract_pages_' . bin2hex(random_bytes(6));
        mkdir($this->tempModulesDir, 0755, true);
        mkdir($this->tempPagesDir, 0755, true);

        // Skema system_modules
        $this->db->getPdo()->exec("
            CREATE TABLE IF NOT EXISTS system_modules (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                version TEXT NOT NULL,
                author TEXT,
                description TEXT,
                category TEXT DEFAULT 'general',
                icon TEXT DEFAULT 'Package',
                route TEXT NOT NULL,
                dependencies TEXT,
                provider TEXT,
                permissions TEXT,
                is_core INTEGER DEFAULT 0,
                is_active INTEGER DEFAULT 1,
                table_name TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS tenant_modules (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                module_id TEXT NOT NULL,
                is_enabled INTEGER DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration TEXT NOT NULL UNIQUE,
                batch INTEGER NOT NULL,
                module TEXT NULL,
                down_sql TEXT NULL,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                slug TEXT NOT NULL,
                description TEXT,
                is_system INTEGER DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(tenant_id, slug)
            );
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                password TEXT NOT NULL,
                is_superadmin INTEGER DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS tenant_user (
                tenant_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                role TEXT NOT NULL,
                PRIMARY KEY (tenant_id, user_id)
            );
            CREATE TABLE IF NOT EXISTS role_permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                role_id INTEGER NOT NULL,
                permission_key TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(tenant_id, role_id, permission_key)
            );
        ");
    }

    protected function tearDown(): void
    {
        $this->recursiveDeleteDirectory($this->tempModulesDir);
        $this->recursiveDeleteDirectory($this->tempPagesDir);
        parent::tearDown();
    }

    private function recursiveDeleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->recursiveDeleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function test_module_scaffold_creates_complete_modern_contract_structure(): void
    {
        $manager = new ModuleManager($this->db->getPdo(), $this->tempModulesDir, $this->tempPagesDir);

        $manager->createModuleScaffold([
            'name' => 'Payroll System',
            'id' => 'payroll',
            'description' => 'Sistem penggajian karyawan terintegrasi',
            'category' => 'finance',
            'icon' => 'Coins',
        ]);

        $folder = $this->tempModulesDir . '/Payroll';
        $this->assertFileExists($folder . '/module.json');
        $this->assertFileExists($folder . '/PayrollServiceProvider.php');
        $this->assertFileExists($folder . '/Database/Migrations/001_create_payroll_table.sql');
        $this->assertFileExists($folder . '/Database/Seeders/PayrollSeeder.php');
        $this->assertFileExists($folder . '/Controllers/PayrollController.php');
        $this->assertFileExists($folder . '/routes.php');
        $this->assertFileExists($folder . '/Pages/Index.tsx');
        $this->assertFileExists($this->tempPagesDir . '/Payroll/Index.tsx');

        // Validasi manifest
        $manifest = json_decode((string) file_get_contents($folder . '/module.json'), true);
        $this->assertSame('payroll', $manifest['id']);
        $this->assertSame('App\\Modules\\Payroll\\PayrollServiceProvider', $manifest['provider']);
        $this->assertContains('payroll.view', $manifest['permissions']);
        $this->assertContains('payroll.create', $manifest['permissions']);

        // Validasi database record
        $stmt = $this->db->getPdo()->query("SELECT * FROM system_modules WHERE id = 'payroll'");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($row);
        $this->assertSame('App\\Modules\\Payroll\\PayrollServiceProvider', $row['provider']);
        $this->assertStringContainsString('payroll.delete', (string) $row['permissions']);
    }

    public function test_autonomous_module_fixture_lifecycle_and_migration_contract(): void
    {
        // 1. Uji Migrasi Modular Up
        $fixtureMigrationsDir = __DIR__ . '/../Fixtures/Modules/SampleContractModule/Database/Migrations';
        $migrator = new Migrator($this->db, $fixtureMigrationsDir);

        $executed = $migrator->runMigrationsFrom($fixtureMigrationsDir, 'sample_contract');
        $this->assertCount(1, $executed);

        // Pastikan tabel sample_contracts ada
        $this->assertTrue($this->db->tableExists('sample_contracts'));

        // Pastikan metadata module & down_sql tercatat di tabel migrations
        $meta = $this->db->selectOne("SELECT module, down_sql FROM migrations WHERE module = 'sample_contract'");
        $this->assertNotNull($meta);
        $this->assertSame('sample_contract', $meta['module']);
        $this->assertStringContainsString('DROP TABLE IF EXISTS sample_contracts', (string) $meta['down_sql']);

        // 2. Uji Seeder Modul
        $seeder = new SampleContractSeeder($this->db);
        $seeder->run();

        $rows = $this->db->select("SELECT * FROM sample_contracts WHERE tenant_id = 1");
        $this->assertCount(1, $rows);
        $this->assertSame('Initial Seeded Contract', $rows[0]['title']);

        // 3. Uji Provider Lifecycle (Register & Boot & Event Dispatcher)
        SampleContractServiceProvider::$registerCalled = false;
        SampleContractServiceProvider::$bootCalled = false;
        SampleContractServiceProvider::$listenerExecuted = false;

        $modulePath = dirname(__DIR__) . '/Fixtures/Modules/SampleContractModule';
        $provider = new SampleContractServiceProvider($this->app, $modulePath);
        $provider->register();
        $this->assertTrue(SampleContractServiceProvider::$registerCalled);
        $this->assertSame('ContractServiceInstance', $this->app->getContainer()->get('sample_contract.service'));

        $provider->boot();
        $this->assertTrue(SampleContractServiceProvider::$bootCalled);

        // Trigger event via EventDispatcher
        /** @var EventDispatcher $dispatcher */
        $dispatcher = $this->app->getContainer()->get(EventDispatcher::class);
        $dispatcher->dispatch(new \Tests\Fixtures\Modules\SampleContractModule\SampleContractEvent(['sample_key' => 'sample_value']));
        $this->assertTrue(SampleContractServiceProvider::$listenerExecuted);

        // 4. Uji Dynamic RBAC dengan Permission yang Dideklarasikan Modul
        $gate = new Gate($this->db, $this->app->getContainer());

        // Masukkan role khusus member dengan permission sample.read
        $this->db->insert('roles', [
            'tenant_id' => 1,
            'name' => 'Auditor',
            'slug' => 'auditor',
        ]);
        $roleId = (int) $this->db->getPdo()->lastInsertId();

        $this->db->insert('role_permissions', [
            'tenant_id' => 1,
            'role_id' => $roleId,
            'permission_key' => 'sample.read',
        ]);

        $this->db->insert('users', [
            'id' => 42,
            'name' => 'Auditor User',
            'email' => 'auditor@example.com',
            'password' => 'secret',
            'is_superadmin' => 0,
        ]);

        $this->db->insert('tenant_user', [
            'tenant_id' => 1,
            'user_id' => 42,
            'role' => 'auditor',
        ]);

        $auditorUserId = 42;
        $this->assertTrue($gate->allows($auditorUserId, 'sample.read', 1, 'auditor'));
        $this->assertFalse($gate->allows($auditorUserId, 'sample.write', 1, 'auditor'));

        // 5. Uji Rute Modul yang Dimuat Otomatis oleh Provider Boot
        /** @var Router $router */
        $router = $this->app->getContainer()->get(Router::class);

        $request = new Request('GET', '/sample-contract/ping');
        $response = $router->dispatch($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('sample_contract', $response->getBody());

        // 6. Uji Rollback Modul Down
        $rolledBack = $migrator->rollbackModule('sample_contract');
        $this->assertCount(1, $rolledBack);

        // Pastikan tabel sample_contracts telah di-drop bersih
        $this->assertFalse($this->db->tableExists('sample_contracts'));
    }
}
