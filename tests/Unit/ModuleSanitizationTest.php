<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Modules\CategoryRegistry;
use Core\Modules\ModuleManager;
use PHPUnit\Framework\TestCase;

class ModuleSanitizationTest extends TestCase
{
    private string $modulesPath;

    protected function setUp(): void
    {
        $this->modulesPath = dirname(__DIR__, 2) . '/app/Modules';
    }

    public function testAllModuleDirectoriesHaveValidManifest(): void
    {
        $folders = scandir($this->modulesPath) ?: [];
        $moduleFolders = array_filter($folders, fn($f) => !in_array($f, ['.', '..'], true) && is_dir($this->modulesPath . '/' . $f));

        $this->assertCount(17, $moduleFolders, 'Harus ada tepat 17 folder modul');

        $requiredKeys = ['id', 'name', 'version', 'author', 'category', 'icon', 'route', 'is_core', 'is_active'];

        foreach ($moduleFolders as $folder) {
            $manifestPath = $this->modulesPath . '/' . $folder . '/module.json';
            $this->assertFileExists($manifestPath, "Folder modul {$folder} harus memiliki file module.json");

            $content = json_decode((string) file_get_contents($manifestPath), true);
            $this->assertIsArray($content, "module.json di {$folder} harus berupa JSON valid");

            foreach ($requiredKeys as $key) {
                $this->assertArrayHasKey($key, $content, "module.json di {$folder} wajib memiliki properti '{$key}'");
            }

            // Kategori harus terdaftar di Universal CategoryRegistry
            $normalizedCategory = CategoryRegistry::normalizeKey((string) $content['category']);
            $this->assertTrue(CategoryRegistry::isValid($normalizedCategory), "Kategori '{$content['category']}' di {$folder} harus valid di CategoryRegistry");
        }
    }

    public function testModuleManagerRecognizesAllEightModules(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $moduleManager = new ModuleManager($pdo, $this->modulesPath);

        $modules = $moduleManager->listModules();
        $this->assertCount(17, $modules, 'ModuleManager harus memuat dan menyinkronkan ke-17 modul');

        $moduleIds = array_column($modules, 'id');
        $expectedIds = ['projects', 'modules', 'auth', 'workspaces', 'activity', 'notifications', 'settings', 'api', 'contact', 'finance', 'inventory', 'billing', 'facility', 'schedule', 'tariff', 'terminology', 'seal'];

        foreach ($expectedIds as $expectedId) {
            $this->assertContains($expectedId, $moduleIds, "Modul ID '{$expectedId}' harus terdaftar di ModuleManager");
        }
    }

    public function testActiveNavItemsExcludesHiddenPlatformModules(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $moduleManager = new ModuleManager($pdo, $this->modulesPath);

        $navItems = $moduleManager->getActiveNavItems();
        $navIds = array_column($navItems, 'id');

        // Modul platform tidak boleh masuk ke dynamic nav items karena sudah ada di base layout atau settings
        $this->assertNotContains('auth', $navIds);
        $this->assertNotContains('workspaces', $navIds);
        $this->assertNotContains('activity', $navIds);
        $this->assertNotContains('notifications', $navIds);
        $this->assertNotContains('settings', $navIds);
        $this->assertNotContains('api', $navIds);

        // Modul bisnis seperti projects harus tetap ada
        $this->assertContains('projects', $navIds);
    }
}
