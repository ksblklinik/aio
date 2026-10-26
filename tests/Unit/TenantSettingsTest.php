<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Settings\Controllers\OrganizationSettingsController;
use App\Modules\Workspaces\Models\Tenant;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;
use Core\Inertia\Inertia;
use Core\Settings\TenantSettings;
use PHPUnit\Framework\TestCase;

class TenantSettingsTest extends TestCase
{
    private Connection $db;
    private Migrator $migrator;
    private TenantSettings $settings;
    private TenantContext $tenantContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new Connection('sqlite::memory:');
        $this->migrator = new Migrator($this->db);
        $this->migrator->runMigrationsFrom(__DIR__ . '/../../database/migrations');

        $this->tenantContext = TenantContext::getInstance();
        $this->tenantContext->clear();

        $this->settings = new TenantSettings($this->db);

        // Bind ke container app untuk testing global helpers
        $container = \Core\Container::getInstance();
        $container->instance(Connection::class, $this->db);
        $container->instance(TenantSettings::class, $this->settings);
    }

    protected function tearDown(): void
    {
        $this->tenantContext->clear();
        parent::tearDown();
    }

    public function test_tenant_table_has_org_profile_columns(): void
    {
        $tenantId = (int) $this->db->insert('tenants', [
            'name' => 'Klinik Medika Utama',
            'slug' => 'klinik-medika',
            'plan' => 'enterprise',
            'org_type' => 'clinic',
            'timezone' => 'Asia/Jakarta',
            'currency' => 'IDR',
            'locale' => 'id_ID',
        ]);

        $tenant = Tenant::findById($this->db, $tenantId);
        $this->assertNotNull($tenant);
        $this->assertSame('Klinik Medika Utama', $tenant->name);
        $this->assertSame('clinic', $tenant->orgType);
        $this->assertSame('Asia/Jakarta', $tenant->timezone);
        $this->assertSame('IDR', $tenant->currency);
        $this->assertSame('id_ID', $tenant->locale);
    }

    public function test_settings_typed_storage_and_retrieval(): void
    {
        $tenantId = 1;

        // String
        $this->settings->set('site_title', 'AIO Clinic App', 'core', $tenantId);
        $this->assertSame('AIO Clinic App', $this->settings->get('site_title', null, $tenantId));

        // Integer
        $this->settings->set('invoice_due_days', 14, 'invoicing', $tenantId);
        $this->assertSame(14, $this->settings->get('invoice_due_days', null, $tenantId));

        // Boolean
        $this->settings->set('tax_enabled', true, 'invoicing', $tenantId);
        $this->assertTrue($this->settings->get('tax_enabled', false, $tenantId));

        // Array / JSON
        $meta = ['prefix' => 'INV', 'digits' => 5];
        $this->settings->set('invoice_meta', $meta, 'invoicing', $tenantId);
        $this->assertSame($meta, $this->settings->get('invoice_meta', null, $tenantId));

        // Fallback default
        $this->assertSame('default_val', $this->settings->get('non_existent', 'default_val', $tenantId));
    }

    public function test_settings_in_memory_cache_prevents_duplicate_queries(): void
    {
        $tenantId = 1;
        $this->settings->set('cached_key', 'initial_value', 'core', $tenantId);

        // Fetch to populate memory cache
        $this->assertSame('initial_value', $this->settings->get('cached_key', null, $tenantId));

        // Directly update raw database row behind the scenes
        $this->db->statement(
            "UPDATE tenant_settings SET value = 'direct_db_value' WHERE tenant_id = ? AND key = ?",
            [$tenantId, 'cached_key']
        );

        // Should still return in-memory cached value
        $this->assertSame('initial_value', $this->settings->get('cached_key', null, $tenantId));

        // After clearing cache, fresh value is fetched
        $this->settings->clearCache();
        $this->assertSame('direct_db_value', $this->settings->get('cached_key', null, $tenantId));
    }

    public function test_settings_has_and_forget(): void
    {
        $tenantId = 1;
        $this->settings->set('temporary_flag', true, 'core', $tenantId);

        $this->assertTrue($this->settings->has('temporary_flag', $tenantId));
        $this->assertFalse($this->settings->has('unknown_flag', $tenantId));

        $this->settings->forget('temporary_flag', $tenantId);
        $this->assertFalse($this->settings->has('temporary_flag', $tenantId));
        $this->assertNull($this->settings->get('temporary_flag', null, $tenantId));
    }

    public function test_settings_all_and_module_filter(): void
    {
        $tenantId = 1;
        $this->settings->set('key_core_1', 'val1', 'core', $tenantId);
        $this->settings->set('key_core_2', 'val2', 'core', $tenantId);
        $this->settings->set('key_inv_1', 'inv_val', 'invoicing', $tenantId);

        $allCore = $this->settings->all('core', $tenantId);
        $this->assertArrayHasKey('key_core_1', $allCore);
        $this->assertArrayHasKey('key_core_2', $allCore);
        $this->assertArrayNotHasKey('key_inv_1', $allCore);

        $allTenant = $this->settings->all(null, $tenantId);
        $this->assertCount(3, $allTenant);
        $this->assertSame('inv_val', $allTenant['key_inv_1']);
    }

    public function test_tenant_profile_crud(): void
    {
        $tenantId = (int) $this->db->insert('tenants', [
            'name' => 'Yayasan Peduli Umat',
            'slug' => 'peduli-umat',
            'plan' => 'pro',
            'org_type' => 'non_profit',
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'locale' => 'id_ID',
        ]);

        $profile = $this->settings->getTenantProfile($tenantId);
        $this->assertSame('non_profit', $profile['org_type']);
        $this->assertSame('Yayasan Peduli Umat', $profile['name']);

        // Update profile
        $this->settings->updateTenantProfile([
            'timezone' => 'Asia/Jakarta',
            'currency' => 'USD',
        ], $tenantId);

        $updated = $this->settings->getTenantProfile($tenantId);
        $this->assertSame('Asia/Jakarta', $updated['timezone']);
        $this->assertSame('USD', $updated['currency']);
    }

    public function test_global_helpers_setting_currency_and_date(): void
    {
        $tenantId = (int) $this->db->insert('tenants', [
            'name' => 'Global Retail Store',
            'slug' => 'global-retail',
            'plan' => 'pro',
            'org_type' => 'retail',
            'timezone' => 'Asia/Jakarta',
            'currency' => 'IDR',
            'locale' => 'id_ID',
        ]);

        $this->tenantContext->setTenantId((string) $tenantId);
        $this->settings->set('store_tagline', 'Belanja Murah Berkualitas', 'core', $tenantId);

        // Helper setting()
        $this->assertSame('Belanja Murah Berkualitas', setting('store_tagline'));
        $this->assertSame('fallback', setting('missing_key', 'fallback'));

        // Helper format_currency() & money()
        $this->assertStringContainsString('50.000', format_currency(50000));
        $this->assertStringContainsString('50.000', money(50000));

        // Format currency dengan explicit override
        $this->assertSame('$100.00', format_currency(100, 'USD', 'en_US'));

        // Helper format_date_local() converts UTC to Asia/Jakarta (+7 hours)
        $utcTime = '2026-10-26 05:00:00';
        $localTime = format_date_local($utcTime, 'H:i');
        $this->assertSame('12:00', $localTime); // 05:00 + 7 hours = 12:00
    }

    public function test_organization_settings_controller_index_and_update(): void
    {
        $tenantId = (int) $this->db->insert('tenants', [
            'name' => 'Original Org Name',
            'slug' => 'original-org',
            'plan' => 'pro',
            'org_type' => 'general',
            'timezone' => 'UTC',
            'currency' => 'USD',
            'locale' => 'en_US',
        ]);

        $tenant = Tenant::findById($this->db, $tenantId);

        $authMock = $this->createMock(AuthService::class);
        $authMock->method('currentTenant')->willReturn($tenant);

        $session = new Session();
        $inertia = new Inertia();

        $controller = new OrganizationSettingsController(
            $authMock,
            $inertia,
            $session,
            $this->settings
        );

        // Test GET index
        $request = new Request('GET', '/settings/organization');
        $response = $controller->index($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Settings\\/Organization', (string) $response->getBody());

        // Test POST update
        $updateRequest = new Request('POST', '/settings/organization', [], [], [
            'name' => 'Updated Org Name',
            'org_type' => 'for_profit',
            'timezone' => 'Asia/Jakarta',
            'currency' => 'IDR',
            'locale' => 'id_ID',
            'company_phone' => '+62 812-3456-7890',
            'company_address' => 'Jl. Jendral Sudirman Kav. 1',
        ]);

        $updateResponse = $controller->update($updateRequest);
        $this->assertSame(302, $updateResponse->getStatusCode());
        $this->assertSame('/settings/organization', $updateResponse->getHeader('Location'));

        // Verify updated in database
        $updatedProfile = $this->settings->getTenantProfile($tenantId);
        $this->assertSame('Updated Org Name', $updatedProfile['name']);
        $this->assertSame('for_profit', $updatedProfile['org_type']);
        $this->assertSame('Asia/Jakarta', $updatedProfile['timezone']);
        $this->assertSame('IDR', $updatedProfile['currency']);
        $this->assertSame('+62 812-3456-7890', $this->settings->get('company_phone', null, $tenantId));
    }
}
