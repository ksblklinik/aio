<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Document\EscPos;
use Core\Foundation\Application;
use PHPUnit\Framework\TestCase;

class CoreDeviceIntegrationTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Application(dirname(__DIR__, 2));
    }

    public function testEscPosBinaryCommands(): void
    {
        $escpos = EscPos::create(cols: 32)
            ->initialize()
            ->align('center')
            ->bold(true)
            ->line('TOKO SERBA ADA')
            ->bold(false)
            ->align('left')
            ->divider('-')
            ->row('Kopi Robusta', '35.000')
            ->divider('=')
            ->row('TOTAL', '35.000')
            ->feed(3)
            ->cut(true)
            ->openDrawer();

        $bin = $escpos->getBinary();

        // Verifikasi kode byte ESC/POS spesifik
        $this->assertStringContainsString("\x1B\x40", $bin, "Harus berisi inisialisasi printer (ESC @)");
        $this->assertStringContainsString("\x1B\x61\x01", $bin, "Harus berisi perataan tengah (ESC a 1)");
        $this->assertStringContainsString("\x1B\x45\x01", $bin, "Harus berisi bold on (ESC E 1)");
        $this->assertStringContainsString('TOKO SERBA ADA', $bin);
        $this->assertStringContainsString("\x1B\x45\x00", $bin, "Harus berisi bold off (ESC E 0)");
        $this->assertStringContainsString(str_repeat('-', 32), $bin, "Harus memiliki garis divider 32 karakter");
        $this->assertStringContainsString("\x1D\x56\x01", $bin, "Harus berisi perintah potong kertas");
        $this->assertStringContainsString("\x1B\x70\x00\x19\xFA", $bin, "Harus berisi perintah buka laci kasir");

        // Verifikasi representasi Base64
        $base64 = $escpos->toBase64();
        $this->assertNotEmpty($base64);
        $this->assertEquals($bin, base64_decode($base64));
    }

    public function testEscPosTwoColumnRowPadding(): void
    {
        $escpos = EscPos::create(cols: 32);
        $escpos->row('Item A', '10.000');
        $bin = $escpos->getBinary();

        // 6 karakter 'Item A' + 6 karakter '10.000' = 12 karakter. Sisa spasi = 20 spasi. Total 32 karakter + \n
        $expected = "Item A" . str_repeat(' ', 20) . "10.000\x0A";
        $this->assertStringContainsString($expected, $bin);
    }

    public function testGlobalEscPosHelper(): void
    {
        $instance = escpos(48);
        $this->assertInstanceOf(EscPos::class, $instance);
    }

    public function testPwaManifestAndServiceWorkerIntegrity(): void
    {
        $manifestPath = dirname(__DIR__, 2) . '/public/manifest.json';
        $this->assertFileExists($manifestPath);

        $manifestContent = file_get_contents($manifestPath);
        $this->assertNotFalse($manifestContent);
        $data = json_decode($manifestContent, true);
        $this->assertIsArray($data);
        $this->assertEquals('standalone', $data['display']);
        $this->assertNotEmpty($data['name']);
        $this->assertNotEmpty($data['theme_color']);

        $swPath = dirname(__DIR__, 2) . '/public/sw.js';
        $this->assertFileExists($swPath);
        $swContent = file_get_contents($swPath);
        $this->assertStringContainsString('CACHE_NAME', (string) $swContent);
        $this->assertStringContainsString('addEventListener(\'fetch\'', (string) $swContent);
    }

    public function testDeviceIntegrationGuideExists(): void
    {
        $docPath = dirname(__DIR__, 2) . '/docs/DEVICE_INTEGRATION_GUIDE.md';
        $this->assertFileExists($docPath);

        $docContent = file_get_contents($docPath);
        $this->assertStringContainsString('BluetoothPrinterClient', (string) $docContent);
        $this->assertStringContainsString('useBarcodeScanner', (string) $docContent);
        $this->assertStringContainsString('SignaturePad', (string) $docContent);
        $this->assertStringContainsString('useGeoTracker', (string) $docContent);
    }
}
