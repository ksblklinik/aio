<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Modules\ModuleManager;
use DomainException;
use PHPUnit\Framework\TestCase;

class ModuleManagerTest extends TestCase
{
    private Connection $db;
    private string $tempModulesDir;
    private string $tempPagesDir;
    private ModuleManager $manager;

    protected function setUp(): void
    {
        $this->db = new Connection('sqlite::memory:');
        $migrator = new Migrator($this->db);
        $migrator->runMigrationsFrom(__DIR__ . '/../../database/migrations');

        // Gunakan real app/Modules untuk tes modul yang sudah ada
        $this->manager = new ModuleManager($this->db->getPdo());

        // Siapkan direktori temp untuk tes scaffolding dan delete
        $this->tempModulesDir = sys_get_temp_dir() . '/aio_test_modules_' . uniqid();
        $this->tempPagesDir = sys_get_temp_dir() . '/aio_test_pages_' . uniqid();
        mkdir($this->tempModulesDir, 0755, true);
        mkdir($this->tempPagesDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempModulesDir);
        $this->removeDirectory($this->tempPagesDir);
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

    public function test_sync_and_list_modules_loads_all_modules(): void
    {
        $modules = $this->manager->listModules();

        $this->assertNotEmpty($modules);
        $ids = array_column($modules, 'id');

        $this->assertContains('auth', $ids);
        $this->assertContains('workspaces', $ids);
        $this->assertContains('projects', $ids);

        // Pastikan Auth dan Workspaces adalah modul core
        foreach ($modules as $m) {
            if ($m['id'] === 'auth' || $m['id'] === 'workspaces') {
                $this->assertTrue($m['is_core']);
            }
        }
    }

    public function test_get_active_modules_and_nav_items(): void
    {
        $active = $this->manager->getActiveModules();
        $this->assertNotEmpty($active);

        $navItems = $this->manager->getActiveNavItems();
        $this->assertNotEmpty($navItems);

        $navIds = array_column($navItems, 'id');
        $this->assertContains('projects', $navIds);
        $this->assertNotContains('auth', $navIds); // Auth tersembunyi dari menu
        $this->assertNotContains('workspaces', $navIds); // Workspaces tersembunyi dari menu
    }

    public function test_core_module_cannot_be_toggled_off(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('dilindungi dan tidak dapat dinonaktifkan');

        $this->manager->toggleActive('auth', false);
    }

    public function test_toggle_non_core_module(): void
    {
        // Toggle Projects off
        $updated = $this->manager->toggleActive('projects', false);
        $this->assertFalse($updated['is_active']);

        // Pastikan tidak lagi muncul di menu navigasi
        $navIds = array_column($this->manager->getActiveNavItems(), 'id');
        $this->assertNotContains('projects', $navIds);

        // Toggle Projects on kembali
        $restored = $this->manager->toggleActive('projects', true);
        $this->assertTrue($restored['is_active']);

        $navIdsRestored = array_column($this->manager->getActiveNavItems(), 'id');
        $this->assertContains('projects', $navIdsRestored);
    }

    public function test_dependency_guard_prevents_deactivating_prerequisite(): void
    {
        // Daftarkan modul buatan 'invoicing' yang membutuhkan 'projects'
        $this->db->getPdo()->exec("INSERT INTO system_modules 
            (id, name, version, author, description, category, icon, route, dependencies, is_core, is_active, table_name)
            VALUES ('invoicing', 'Invoicing', '1.0.0', 'Tester', 'Billing', 'Finance', 'DollarSign', '/invoices', '[\"projects\"]', 0, 1, 'invoices')");

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('dibutuhkan oleh modul aktif: Invoicing');

        // Mencoba mematikan projects harus diblokir oleh Dependency Guard
        $this->manager->toggleActive('projects', false);
    }

    public function test_scaffold_creates_module_files_and_records_to_database(): void
    {
        $isolatedManager = new ModuleManager($this->db->getPdo(), $this->tempModulesDir, $this->tempPagesDir);

        $isolatedManager->createModuleScaffold([
            'name' => 'Support Desk',
            'id' => 'support',
            'description' => 'Helpdesk tiket untuk pelanggan',
            'category' => 'Bantuan',
            'icon' => 'LifeBuoy',
            'dependencies' => ['auth'],
        ]);

        // Periksa file backend
        $this->assertFileExists($this->tempModulesDir . '/Support/module.json');
        $this->assertFileExists($this->tempModulesDir . '/Support/Controllers/SupportController.php');
        $this->assertFileExists($this->tempModulesDir . '/Support/routes.php');

        // Periksa file frontend
        $this->assertFileExists($this->tempPagesDir . '/Support/Index.tsx');

        // Periksa record di database
        $stmt = $this->db->getPdo()->prepare("SELECT * FROM system_modules WHERE id = 'support'");
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($row);
        $this->assertSame('Support Desk', $row['name']);
        $this->assertSame('Helpdesk tiket untuk pelanggan', $row['description']);
    }

    public function test_delete_module_clean_wipes_files_and_drops_table(): void
    {
        $isolatedManager = new ModuleManager($this->db->getPdo(), $this->tempModulesDir, $this->tempPagesDir);

        // Buat tabel dummy untuk dites drop
        $this->db->getPdo()->exec("CREATE TABLE custom_temp (id INT)");

        $isolatedManager->createModuleScaffold([
            'name' => 'Custom Temp',
            'id' => 'custom_temp',
        ]);

        $this->assertDirectoryExists($this->tempModulesDir . '/Custom_temp');

        // Eksekusi Clean Wipe
        $result = $isolatedManager->deleteModule('custom_temp');
        $this->assertTrue($result);

        // Pastikan folder terhapus
        $this->assertDirectoryDoesNotExist($this->tempModulesDir . '/Custom_temp');
        $this->assertDirectoryDoesNotExist($this->tempPagesDir . '/Custom_temp');

        // Pastikan tabel di database terhapus (Clean Wipe)
        $stmt = $this->db->getPdo()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='custom_temp'");
        $this->assertFalse($stmt->fetch());

        // Pastikan record di system_modules terhapus
        $stmt = $this->db->getPdo()->query("SELECT id FROM system_modules WHERE id='custom_temp'");
        $this->assertFalse($stmt->fetch());
    }

    public function test_cannot_delete_core_module(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('dilindungi dan tidak dapat dihapus');

        $this->manager->deleteModule('auth');
    }
}
