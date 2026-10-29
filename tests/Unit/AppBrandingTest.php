<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Core\Config\Config;
use Core\Inertia\Inertia;
use Core\Http\Request;
use Core\Session\Session;
use Core\Foundation\Application;

class AppBrandingTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/core/helpers.php';
    }

    public function test_asset_helper_resolves_relative_and_absolute_urls(): void
    {
        $this->assertTrue(function_exists('asset'), 'Helper asset() harus terdefinisi di core/helpers.php');

        // URL absolut http / https tidak dimodifikasi
        $this->assertSame('https://example.com/logo.png', asset('https://example.com/logo.png'));
        $this->assertSame('http://cdn.org/favicon.ico', asset('http://cdn.org/favicon.ico'));

        // Path relatif dinormalisasi dengan awalan slash
        $resolved = asset('images/logo.png');
        $this->assertStringEndsWith('/images/logo.png', $resolved);

        $resolvedFavicon = asset('/favicon.ico');
        $this->assertStringEndsWith('/favicon.ico', $resolvedFavicon);
    }

    public function test_config_app_contains_branding_keys(): void
    {
        $configPath = dirname(__DIR__, 2) . '/config/app.php';
        $this->assertFileExists($configPath);

        $config = require $configPath;
        $this->assertIsArray($config);

        $this->assertArrayHasKey('name', $config);
        $this->assertArrayHasKey('tagline', $config);
        $this->assertArrayHasKey('logo', $config);
        $this->assertArrayHasKey('favicon', $config);

        $this->assertSame('aio SaaS', $config['name']);
        $this->assertSame('Unified Platform', $config['tagline']);
        $this->assertNull($config['logo']);
        $this->assertSame('/favicon.ico', $config['favicon']);
    }

    public function test_root_view_renders_dynamic_title_and_favicon(): void
    {
        $viewPath = dirname(__DIR__, 2) . '/resources/views/app.php';
        $this->assertFileExists($viewPath);

        // Simulasi rendering view app.php dengan props custom
        $page = [
            'component' => 'Dashboard',
            'props' => [
                'title' => 'Ringkasan Eksekutif',
                'app' => [
                    'name' => 'Klinik Sehat Mandiri',
                    'tagline' => 'Sistem Informasi Layanan Terpadu',
                    'logo' => '/assets/klinik-logo.png',
                    'favicon' => '/assets/klinik-favicon.ico',
                ],
            ],
            'url' => '/dashboard',
            'version' => '1.0.0',
        ];

        ob_start();
        include $viewPath;
        $output = (string) ob_get_clean();

        // Verifikasi title memuat judul halaman dan nama aplikasi kustom
        $this->assertStringContainsString('<title>Ringkasan Eksekutif - Klinik Sehat Mandiri</title>', $output);
        // Verifikasi link favicon kustom dirender
        $this->assertStringContainsString('<link rel="icon" href="/assets/klinik-favicon.ico">', $output);
    }

    public function test_root_view_fallback_title_when_no_page_title_provided(): void
    {
        $viewPath = dirname(__DIR__, 2) . '/resources/views/app.php';

        $page = [
            'component' => 'Welcome',
            'props' => [
                'app' => [
                    'name' => 'Klinik Sehat Mandiri',
                    'tagline' => 'Solusi Medis Modern',
                    'favicon' => '/favicon.ico',
                ],
            ],
            'url' => '/',
            'version' => '1.0.0',
        ];

        ob_start();
        include $viewPath;
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('<title>Klinik Sehat Mandiri - Solusi Medis Modern</title>', $output);
        $this->assertStringContainsString('<link rel="icon" href="/favicon.ico">', $output);
    }
}
