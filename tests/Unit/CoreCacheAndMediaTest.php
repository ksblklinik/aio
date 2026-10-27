<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Cache\ArrayCacheDriver;
use Core\Cache\CacheManager;
use Core\Cache\FileCacheDriver;
use Core\Database\TenantContext;
use Core\Foundation\Application;
use Core\Media\Barcode;
use Core\Media\QrCode;
use PHPUnit\Framework\TestCase;

class CoreCacheAndMediaTest extends TestCase
{
    private string $tempCacheDir;
    private TenantContext $tenantContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempCacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aio_test_cache_' . bin2hex(random_bytes(4));
        if (!is_dir($this->tempCacheDir)) {
            mkdir($this->tempCacheDir, 0755, true);
        }

        $this->tenantContext = TenantContext::getInstance();
        $this->tenantContext->setTenantId('1', ['name' => 'Yayasan Sejahtera']);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempCacheDir)) {
            $files = glob($this->tempCacheDir . DIRECTORY_SEPARATOR . '*');
            if ($files) {
                foreach ($files as $f) {
                    @unlink($f);
                }
            }
            @rmdir($this->tempCacheDir);
        }
        parent::tearDown();
    }

    public function test_array_cache_driver_crud_and_ttl(): void
    {
        $driver = new ArrayCacheDriver();

        $this->assertNull($driver->get('non_existent'));
        $this->assertSame('default_val', $driver->get('non_existent', 'default_val'));

        $this->assertTrue($driver->put('user_count', 42, 3600));
        $this->assertTrue($driver->has('user_count'));
        $this->assertSame(42, $driver->get('user_count'));

        // Forget
        $this->assertTrue($driver->forget('user_count'));
        $this->assertFalse($driver->has('user_count'));

        // Flush
        $driver->put('a', 1);
        $driver->put('b', 2);
        $this->assertTrue($driver->flush());
        $this->assertNull($driver->get('a'));
        $this->assertNull($driver->get('b'));

        // TTL expiration
        $driver->put('fast_expire', 'quick', -10); // Expired immediately
        $this->assertNull($driver->get('fast_expire'));
    }

    public function test_file_cache_driver_crud_and_ttl(): void
    {
        $driver = new FileCacheDriver($this->tempCacheDir);

        $this->assertNull($driver->get('file_key'));
        $this->assertTrue($driver->put('file_key', ['name' => 'AIO Framework'], 3600));

        $this->assertTrue($driver->has('file_key'));
        $this->assertSame(['name' => 'AIO Framework'], $driver->get('file_key'));

        $this->assertTrue($driver->forget('file_key'));
        $this->assertNull($driver->get('file_key'));

        // Flush
        $driver->put('item1', 'A');
        $driver->put('item2', 'B');
        $this->assertTrue($driver->flush());
        $this->assertNull($driver->get('item1'));
        $this->assertNull($driver->get('item2'));

        // TTL expiration
        $driver->put('expired_file', 'stale', -5);
        $this->assertNull($driver->get('expired_file'));
    }

    public function test_cache_manager_tenant_isolation_and_remember(): void
    {
        $arrayDriver = new ArrayCacheDriver();
        $manager = new CacheManager($arrayDriver, $this->tenantContext, 'array');

        // Tenant 1
        $this->tenantContext->setTenantId('1');
        $manager->put('config_site', 'Nilai Tenant 1');

        // Tenant 2
        $this->tenantContext->setTenantId('2');
        $manager->put('config_site', 'Nilai Tenant 2');

        // Check isolation
        $this->tenantContext->setTenantId('1');
        $this->assertSame('Nilai Tenant 1', $manager->get('config_site'));

        $this->tenantContext->setTenantId('2');
        $this->assertSame('Nilai Tenant 2', $manager->get('config_site'));

        // Global key
        $manager->put('global:version', '1.0.0', 3600);
        $this->tenantContext->setTenantId('1');
        $this->assertSame('1.0.0', $manager->get('global:version'));
        $this->tenantContext->setTenantId('2');
        $this->assertSame('1.0.0', $manager->get('global:version'));

        // Remember callback
        $callCount = 0;
        $val1 = $manager->remember('expensive_stat', 3600, function () use (&$callCount) {
            $callCount++;
            return 999;
        });

        $this->assertSame(999, $val1);
        $this->assertSame(1, $callCount);

        // Second call hits cache (callback not executed)
        $val2 = $manager->remember('expensive_stat', 3600, function () use (&$callCount) {
            $callCount++;
            return 999;
        });

        $this->assertSame(999, $val2);
        $this->assertSame(1, $callCount);
    }

    public function test_qr_code_svg_and_data_uri_generation(): void
    {
        $shortSvg = QrCode::svg('https://aio.dev/verify/SK-001', 250);

        $this->assertStringStartsWith('<svg', $shortSvg);
        $this->assertStringEndsWith('</svg>', trim($shortSvg));
        $this->assertStringContainsString('viewBox="0 0', $shortSvg);
        $this->assertStringContainsString('width="250"', $shortSvg);
        $this->assertStringContainsString('height="250"', $shortSvg);
        $this->assertStringContainsString('<path d="M', $shortSvg);

        // Long text auto versioning (version > 1)
        $longText = 'https://portal.yayasan.org/notulen/keputusan-rapat-pleno-pembina-tahunan-nomor-001-tahun-2026';
        $longSvg = QrCode::svg($longText);
        $this->assertStringStartsWith('<svg', $longSvg);
        $this->assertStringContainsString('<path d="M', $longSvg);

        // Data URI
        $dataUri = QrCode::dataUri('test-code');
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $dataUri);
    }

    public function test_barcode_code128_svg_generation(): void
    {
        $svgWithText = Barcode::svg('SK-2026-001', 320, 90, true);

        $this->assertStringStartsWith('<svg', $svgWithText);
        $this->assertStringEndsWith('</svg>', trim($svgWithText));
        $this->assertStringContainsString('width="320"', $svgWithText);
        $this->assertStringContainsString('height="90"', $svgWithText);
        $this->assertStringContainsString('<rect x=', $svgWithText);
        $this->assertStringContainsString('>SK-2026-001</text>', $svgWithText);

        // Without text label
        $svgNoText = Barcode::svg('1234567890', 200, 50, false);
        $this->assertStringNotContainsString('<text', $svgNoText);
        $this->assertStringContainsString('<rect', $svgNoText);
    }

    public function test_global_helpers_cache_qr_code_barcode(): void
    {
        $app = new Application(dirname(__DIR__, 2));

        // cache() helper
        $this->assertInstanceOf(CacheManager::class, cache());
        cache()->put('helper_test', 'success', 60);
        $this->assertSame('success', cache('helper_test'));

        // qr_code() helper
        $qr = qr_code('https://aio.dev', 150);
        $this->assertStringStartsWith('<svg', $qr);

        // barcode() helper
        $bc = barcode('RESI-8899', 300, 70);
        $this->assertStringStartsWith('<svg', $bc);
    }
}
