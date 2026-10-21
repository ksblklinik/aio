<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Modules\CategoryRegistry;
use Core\Modules\ModuleManager;
use PHPUnit\Framework\TestCase;

class CategoryRegistryTest extends TestCase
{
    private Connection $db;
    private ModuleManager $moduleManager;

    protected function setUp(): void
    {
        $this->db = new Connection('sqlite::memory:');
        $migrator = new Migrator($this->db);
        $migrator->runMigrationsFrom(__DIR__ . '/../../database/migrations');

        $this->moduleManager = new ModuleManager($this->db->getPdo());
    }

    public function testGetAllReturnsEightUniversalCategories(): void
    {
        $categories = CategoryRegistry::all();
        $this->assertCount(8, $categories);

        $expectedIds = [
            'core',
            'collaboration',
            'people',
            'knowledge',
            'resources',
            'engagement',
            'analytics',
            'developer',
        ];

        $actualIds = array_column($categories, 'id');
        $this->assertEquals($expectedIds, $actualIds);

        foreach ($categories as $cat) {
            $this->assertArrayHasKey('id', $cat);
            $this->assertArrayHasKey('label', $cat);
            $this->assertArrayHasKey('icon', $cat);
            $this->assertArrayHasKey('color', $cat);
            $this->assertArrayHasKey('badge_class', $cat);
            $this->assertArrayHasKey('description', $cat);
        }
    }

    public function testNormalizeKeyPreservesValidCategoryIds(): void
    {
        $this->assertSame('core', CategoryRegistry::normalizeKey('core'));
        $this->assertSame('collaboration', CategoryRegistry::normalizeKey('collaboration'));
        $this->assertSame('people', CategoryRegistry::normalizeKey('people'));
        $this->assertSame('knowledge', CategoryRegistry::normalizeKey('knowledge'));
        $this->assertSame('resources', CategoryRegistry::normalizeKey('resources'));
        $this->assertSame('engagement', CategoryRegistry::normalizeKey('engagement'));
        $this->assertSame('analytics', CategoryRegistry::normalizeKey('analytics'));
        $this->assertSame('developer', CategoryRegistry::normalizeKey('developer'));
    }

    public function testNormalizeKeyMapsLegacyAliases(): void
    {
        $this->assertSame('collaboration', CategoryRegistry::normalizeKey('bisnis'));
        $this->assertSame('collaboration', CategoryRegistry::normalizeKey('produktivitas'));
        $this->assertSame('core', CategoryRegistry::normalizeKey('keamanan & akses'));
        $this->assertSame('core', CategoryRegistry::normalizeKey('infrastruktur tenancy'));
        $this->assertSame('core', CategoryRegistry::normalizeKey('sistem & administrasi'));
        $this->assertSame('engagement', CategoryRegistry::normalizeKey('komunikasi & crm'));
        $this->assertSame('resources', CategoryRegistry::normalizeKey('keuangan'));
        $this->assertSame('people', CategoryRegistry::normalizeKey('anggota'));
        $this->assertSame('knowledge', CategoryRegistry::normalizeKey('dokumen'));
        $this->assertSame('analytics', CategoryRegistry::normalizeKey('laporan'));
    }

    public function testNormalizeKeyFallsBackSafelyOnUnknownCategory(): void
    {
        $this->assertSame('collaboration', CategoryRegistry::normalizeKey('unknown_category_xyz'));
        $this->assertSame('collaboration', CategoryRegistry::normalizeKey(''));
    }

    public function testGetAndHelperMethods(): void
    {
        $core = CategoryRegistry::get('core');
        $this->assertNotNull($core);
        $this->assertSame('Core & Keamanan', $core['label']);

        $this->assertNull(CategoryRegistry::get('non_existent'));

        $this->assertTrue(CategoryRegistry::isValid('people'));
        $this->assertFalse(CategoryRegistry::isValid('non_existent'));

        $this->assertSame('Anggota & Keanggotaan', CategoryRegistry::getLabel('people'));
        $this->assertSame('Sumber Daya & Finansial', CategoryRegistry::getLabel('resources'));
        $this->assertSame('Non Existent', CategoryRegistry::getLabel('non_existent'));

        $this->assertStringContainsString('amber', CategoryRegistry::getBadgeClass('resources'));
        $this->assertStringContainsString('slate', CategoryRegistry::getBadgeClass('unknown'));

        $this->assertSame('BarChart3', CategoryRegistry::getIcon('analytics'));
        $this->assertSame('Package', CategoryRegistry::getIcon('unknown'));
    }

    public function testInstalledModulesAreEnrichedWithUniversalTaxonomy(): void
    {
        $modules = $this->moduleManager->listModules();
        $this->assertNotEmpty($modules);

        $validIds = array_keys(CategoryRegistry::map());

        foreach ($modules as $module) {
            $this->assertArrayHasKey('category_id', $module);
            $this->assertArrayHasKey('category_label', $module);
            $this->assertArrayHasKey('category_badge_class', $module);

            $this->assertContains($module['category_id'], $validIds, "Module {$module['id']} category_id is invalid.");
            $this->assertNotEmpty($module['category_label']);
            $this->assertNotEmpty($module['category_badge_class']);
        }
    }
}
