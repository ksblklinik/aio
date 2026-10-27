<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Document\DocumentBuilder;
use Core\Export\CsvExporter;
use Core\Export\CsvImporter;
use Core\Export\ExcelExporter;
use Core\Export\ExporterManager;
use Core\Foundation\Application;
use PHPUnit\Framework\TestCase;

class CoreImportExportAndDocumentTest extends TestCase
{
    private Application $app;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Application(dirname(__DIR__, 2));
        $this->tempDir = sys_get_temp_dir() . '/aio_test_export_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up temporary files
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            if ($files) {
                foreach ($files as $f) {
                    if (is_file($f)) {
                        unlink($f);
                    }
                }
            }
            rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    // ========================================================
    // 1. CSV EXPORTER TESTS
    // ========================================================

    public function testCsvExporterStreamAndToString(): void
    {
        $exporter = new CsvExporter();
        $headers = ['Nama', 'Email', 'Kota'];
        $records = [
            ['Budi Santoso', 'budi@example.com', 'Surabaya'],
            ['Siti, Nurhaliza', 'siti@example.com', 'Jakarta Selatan'],
        ];

        $csvString = $exporter->toString($records, $headers);

        // Harus mengandung UTF-8 BOM
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csvString);
        // Harus meng-escape koma dalam teks
        $this->assertStringContainsString('"Siti, Nurhaliza"', $csvString);
        $this->assertStringContainsString('budi@example.com', $csvString);
    }

    public function testCsvExporterToFileAndDownloadHeaders(): void
    {
        $exporter = new CsvExporter();
        $filePath = $this->tempDir . '/donasi.csv';
        $count = $exporter->toFile([
            ['DON-001', 500000],
            ['DON-002', 250000],
        ], ['No Ref', 'Nominal'], $filePath);

        $this->assertEquals(2, $count);
        $this->assertFileExists($filePath);

        $headers = $exporter->downloadHeaders('laporan_kas');
        $this->assertEquals('text/csv; charset=UTF-8', $headers['Content-Type']);
        $this->assertEquals('attachment; filename="laporan_kas.csv"', $headers['Content-Disposition']);
    }

    // ========================================================
    // 2. EXCEL EXPORTER (SPREADSHEETML XML) TESTS
    // ========================================================

    public function testExcelExporterGeneratesValidSpreadsheetXml(): void
    {
        $excel = new ExcelExporter();
        $headers = ['Kode Barang', 'Nama Produk', 'Harga', 'Stok'];
        $data = [
            ['PRD-01', 'Kopi Robusta 250g', 35000.0, 15],
            ['PRD-02', 'Teh Hitam Celup', 12500.0, 40],
        ];

        $xml = $excel->generate($data, $headers, 'Katalog Produk');

        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        $this->assertStringContainsString('<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"', $xml);
        $this->assertStringContainsString('<Worksheet ss:Name="Katalog Produk">', $xml);
        $this->assertStringContainsString('<Cell ss:StyleID="Header"><Data ss:Type="String">Nama Produk</Data></Cell>', $xml);
        // Verifikasi tipe data angka otomatis
        $this->assertStringContainsString('<Data ss:Type="Number">35000</Data>', $xml);
        $this->assertStringContainsString('<Data ss:Type="Number">15</Data>', $xml);

        $filePath = $this->tempDir . '/katalog.xls';
        $writtenCount = $excel->toFile($data, $headers, $filePath, 'Katalog Produk');
        $this->assertEquals(2, $writtenCount);
        $this->assertFileExists($filePath);

        $headersHttp = $excel->downloadHeaders('rekap_penjualan');
        $this->assertEquals('application/vnd.ms-excel; charset=UTF-8', $headersHttp['Content-Type']);
        $this->assertEquals('attachment; filename="rekap_penjualan.xls"', $headersHttp['Content-Disposition']);
    }

    // ========================================================
    // 3. STREAMED CSV IMPORTER & VALIDATOR TESTS
    // ========================================================

    public function testCsvImporterParsesAndValidatesRows(): void
    {
        $importer = new CsvImporter();
        $csvFile = $this->tempDir . '/import_users.csv';

        // Buat file CSV dengan campuran baris valid dan baris tidak valid
        $content = "Nama,Email,Usia\n" .
                   "Rian Hidayat,rian@test.com,28\n" .
                   "Invalid User,bukan-email-valid,abc\n" .
                   "Dewi Sartika,dewi@test.com,32\n";
        file_put_contents($csvFile, $content);

        $importedRows = [];
        $rules = [
            'Nama' => 'required',
            'Email' => 'required|email',
            'Usia' => 'required|numeric',
        ];

        $result = $importer->parse($csvFile, function (array $row, int $lineNum) use (&$importedRows) {
            $importedRows[] = $row;
        }, $rules);

        $this->assertEquals(3, $result['total']);
        $this->assertEquals(2, $result['imported']);
        $this->assertEquals(1, $result['failed']);

        // Baris 3 gagal validasi email dan numeric
        $this->assertArrayHasKey(3, $result['errors']);
        $this->assertArrayHasKey('Email', $result['errors'][3]);
        $this->assertArrayHasKey('Usia', $result['errors'][3]);

        // Baris valid berhasil diimpor
        $this->assertCount(2, $importedRows);
        $this->assertEquals('Rian Hidayat', $importedRows[0]['Nama']);
        $this->assertEquals('Dewi Sartika', $importedRows[1]['Nama']);
    }

    // ========================================================
    // 4. PRINTABLE DOCUMENT BUILDER TESTS
    // ========================================================

    public function testDocumentBuilderA4Layout(): void
    {
        $doc = DocumentBuilder::create('a4')
            ->organization('Yayasan Amanah Sejahtera', 'Jl. Sudirman No. 45, Jakarta', 'info@amanah.org')
            ->title('FAKTUR PENJUALAN', 'Salinan Asli untuk Pelanggan')
            ->meta('Nomor', 'INV-2026-008')
            ->meta('Tanggal', '27 Oktober 2026')
            ->item('Layanan Cloud Server', 1, 1500000.0, 'Bulan')
            ->item('Setup Domain & SSL', 2, 250000.0, 'Unit')
            ->total('Subtotal', 2000000.0)
            ->total('Grand Total', 2000000.0, true)
            ->watermark('LUNAS')
            ->notes('Pembayaran via transfer BCA.')
            ->withQr('https://aio.test/verify/INV-2026-008')
            ->withBarcode('INV2026008')
            ->signature('Manajer Keuangan', 'Hendro Wicaksono');

        $html = $doc->render();

        $this->assertStringContainsString('Yayasan Amanah Sejahtera', $html);
        $this->assertStringContainsString('FAKTUR PENJUALAN', $html);
        $this->assertStringContainsString('Layanan Cloud Server', $html);
        $this->assertStringContainsString('Rp 1.500.000', $html);
        $this->assertStringContainsString('Rp 2.000.000', $html);
        $this->assertStringContainsString('LUNAS', $html);
        $this->assertStringContainsString('Hendro Wicaksono', $html);
        // Verifikasi SVG QR & Barcode tertanam
        $this->assertStringContainsString('<svg', $html);
    }

    public function testDocumentBuilderThermalLayouts(): void
    {
        $doc58 = DocumentBuilder::create('thermal_58mm')
            ->organization('Toko Berkah Mart')
            ->title('STRUK KASIR')
            ->meta('Kasir', 'Budi')
            ->item('Beras 5kg', 1, 65000.0)
            ->total('Total', 65000.0, true)
            ->withQr('https://aio.test/receipt/123')
            ->withBarcode('STRUK123');

        $html58 = $doc58->render();
        $this->assertStringContainsString('58mm', $html58);
        $this->assertStringContainsString('Toko Berkah Mart', $html58);
        $this->assertStringContainsString('Beras 5kg', $html58);
        $this->assertStringContainsString('TERIMA KASIH', $html58);

        $doc80 = DocumentBuilder::create('thermal_80mm')
            ->organization('Kafe Sedap Malam')
            ->title('PESANAN MEJA 04')
            ->total('Total', 120000.0, true);

        $html80 = $doc80->render();
        $this->assertStringContainsString('80mm', $html80);
        $this->assertStringContainsString('Kafe Sedap Malam', $html80);
    }

    public function testDocumentBuilderReceiptSlip(): void
    {
        $doc = DocumentBuilder::create('receipt')
            ->organization('Lembaga Amil Zakat AIO')
            ->title('KUITANSI DONASI')
            ->meta('Telah Diterima Dari', 'H. Ridwan Kamil')
            ->meta('Untuk Pembayaran', 'Zakat Maal Tahun 1448 H')
            ->total('Jumlah', 5000000.0, true)
            ->notes('Semoga berkah dan dilipatgandakan pahalanya.')
            ->withQr('https://aio.test/zakat/888');

        $html = $doc->render();
        $this->assertStringContainsString('KUITANSI DONASI', $html);
        $this->assertStringContainsString('Lembaga Amil Zakat AIO', $html);
        $this->assertStringContainsString('H. Ridwan Kamil', $html);
        $this->assertStringContainsString('Rp 5.000.000', $html);
    }

    // ========================================================
    // 5. GLOBAL HELPERS TESTS
    // ========================================================

    public function testGlobalHelpers(): void
    {
        $this->assertInstanceOf(ExporterManager::class, exporter());
        $this->assertInstanceOf(CsvExporter::class, exporter()->csv());
        $this->assertInstanceOf(ExcelExporter::class, exporter()->excel());
        $this->assertInstanceOf(CsvImporter::class, exporter()->importer());
        $this->assertInstanceOf(DocumentBuilder::class, document('a4'));
    }
}
