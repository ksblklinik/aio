<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ServerEnvironmentTest extends TestCase
{
    public function test_public_index_has_cli_server_static_file_bypass(): void
    {
        $indexPath = dirname(__DIR__, 2) . '/public/index.php';
        $this->assertFileExists($indexPath, 'public/index.php harus ada.');

        $content = file_get_contents($indexPath);
        $this->assertIsString($content);

        // Pastikan guard php_sapi_name() === 'cli-server' dan return false ada untuk mencegah 404 pada aset statis
        $this->assertStringContainsString("php_sapi_name() === 'cli-server'", $content, 
            'public/index.php WAJIB memiliki pemeriksaan php_sapi_name() === "cli-server" agar PHP built-in server melayani file statis secara langsung.'
        );
        $this->assertStringContainsString('return false;', $content, 
            'public/index.php WAJIB menyertakan "return false;" di dalam guard cli-server untuk membebaskan aset statis dari router.'
        );
    }

    public function test_production_build_manifest_assets_exist_on_disk(): void
    {
        $manifestPath = dirname(__DIR__, 2) . '/public/build/manifest.json';
        if (!file_exists($manifestPath)) {
            $this->markTestSkipped('public/build/manifest.json belum dibangun (mode dev).');
        }

        $manifestContent = file_get_contents($manifestPath);
        $this->assertIsString($manifestContent);

        $manifest = json_decode($manifestContent, true);
        $this->assertIsArray($manifest, 'Format manifest.json harus berupa JSON array/object yang valid.');

        $buildDir = dirname(__DIR__, 2) . '/public/build/';

        foreach ($manifest as $entryKey => $entry) {
            if (isset($entry['file'])) {
                $filePath = $buildDir . $entry['file'];
                $this->assertFileExists($filePath, "File aset yang dirujuk oleh manifest [{$entry['file']}] harus ada di disk.");
                $this->assertGreaterThan(0, filesize($filePath), "File aset [{$entry['file']}] tidak boleh kosong (0 bytes).");
            }

            if (isset($entry['css']) && is_array($entry['css'])) {
                foreach ($entry['css'] as $cssFile) {
                    $cssPath = $buildDir . $cssFile;
                    $this->assertFileExists($cssPath, "File CSS yang dirujuk oleh manifest [{$cssFile}] harus ada di disk.");
                    $this->assertGreaterThan(0, filesize($cssPath), "File CSS [{$cssFile}] tidak boleh kosong (0 bytes).");
                }
            }
        }
    }

    public function test_vite_asset_helper_renders_script_tags(): void
    {
        require_once dirname(__DIR__, 2) . '/core/helpers.php';
        $this->assertTrue(function_exists('vite_asset'));

        $output = vite_asset('resources/js/app.tsx');
        $this->assertIsString($output);
        $this->assertNotEmpty($output);
        // Harus mengandung tag script module
        $this->assertStringContainsString('<script type="module"', $output);
    }
}
