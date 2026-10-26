<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Container;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use Core\Foundation\Application;
use Core\Http\Request;
use Core\Http\UploadedFile;
use Core\Router;
use Core\Storage\FileDownloadController;
use Core\Storage\LocalStorageDriver;
use Core\Storage\StorageManager;
use Core\Validation\Validator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TenantStorageTest extends TestCase
{
    private Connection $db;
    private Application $app;
    private string $tempStorageDir;
    private StorageManager $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new Connection('sqlite::memory:');
        $this->app = new Application(dirname(__DIR__, 2));
        $this->app->getContainer()->instance(Connection::class, $this->db);

        $this->tempStorageDir = sys_get_temp_dir() . '/aio_storage_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempStorageDir, 0755, true);

        // Jalankan migrasi files table
        $migrationsDir = dirname(__DIR__, 2) . '/database/migrations';
        $migrator = new Migrator($this->db, $migrationsDir);
        $migrator->runMigrationsFrom($migrationsDir, 'core');

        $driver = new LocalStorageDriver($this->tempStorageDir);
        $this->storage = new StorageManager($driver, $this->db, 'test-secret-app-key-32chars!!');
        $this->app->getContainer()->instance(StorageManager::class, $this->storage);

        TenantContext::getInstance()->clear();
    }

    protected function tearDown(): void
    {
        $this->recursiveDeleteDirectory($this->tempStorageDir);
        TenantContext::getInstance()->clear();
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

    public function test_local_storage_driver_crud_and_traversal_guard(): void
    {
        $driver = new LocalStorageDriver($this->tempStorageDir);

        $this->assertTrue($driver->put('invoices/inv-001.txt', 'Invoice Content 123'));
        $this->assertTrue($driver->exists('invoices/inv-001.txt'));
        $this->assertSame('Invoice Content 123', $driver->get('invoices/inv-001.txt'));
        $this->assertSame(19, $driver->size('invoices/inv-001.txt'));

        // Path Traversal Security Guard
        $this->expectException(InvalidArgumentException::class);
        $driver->path('../secret.env');
    }

    public function test_storage_manager_isolates_files_per_tenant(): void
    {
        // Tenant 10 simpan file
        $storage10 = $this->storage->forTenant(10);
        $storage10->put('reports/annual.txt', 'Tenant 10 Annual Report');

        // Tenant 20 simpan file dengan nama relatif sama
        $storage20 = $this->storage->forTenant(20);
        $storage20->put('reports/annual.txt', 'Tenant 20 Annual Report');

        // Pastikan isi tidak tertukar
        $this->assertSame('Tenant 10 Annual Report', $storage10->get('reports/annual.txt'));
        $this->assertSame('Tenant 20 Annual Report', $storage20->get('reports/annual.txt'));

        // Cek fisik folder di disk
        $this->assertFileExists($this->tempStorageDir . '/tenants/10/reports/annual.txt');
        $this->assertFileExists($this->tempStorageDir . '/tenants/20/reports/annual.txt');
    }

    public function test_uploaded_file_store_records_metadata_and_checksum(): void
    {
        $tempUploadFile = $this->tempStorageDir . '/sample_upload.pdf';
        file_put_contents($tempUploadFile, '%PDF-1.4 Mock PDF Content For Tenant');

        $uploadedFile = new UploadedFile(
            name: 'Dokumen_Akta_Notaris.pdf',
            type: 'application/pdf',
            tmpName: $tempUploadFile,
            error: UPLOAD_ERR_OK,
            size: filesize($tempUploadFile) ?: 0
        );

        $storageTenant = $this->storage->forTenant(15);
        $saved = $storageTenant->store($uploadedFile, 'legal', false, 'TenantLegalDoc', 1, 99);

        $this->assertNotEmpty($saved['id']);
        $this->assertSame(15, (int) $saved['tenant_id']);
        $this->assertSame('Dokumen_Akta_Notaris.pdf', $saved['original_name']);
        $this->assertSame('application/pdf', $saved['mime_type']);
        $this->assertSame(hash('sha256', '%PDF-1.4 Mock PDF Content For Tenant'), $saved['checksum_sha256']);
        $this->assertFileExists($this->tempStorageDir . '/tenants/15/' . $saved['file_path']);

        // Isolasi database: Tenant lain tidak dapat mengambil file ini
        $storageOther = $this->storage->forTenant(99);
        $this->assertNull($storageOther->getFile((int) $saved['id']));

        // Tenant pemilik dapat mengambilnya
        $this->assertNotNull($storageTenant->getFile((int) $saved['id']));
    }

    public function test_signed_temporary_url_and_download_controller(): void
    {
        $tempUploadFile = $this->tempStorageDir . '/receipt.png';
        file_put_contents($tempUploadFile, 'FAKE_PNG_BINARY_DATA');

        $uploadedFile = new UploadedFile(
            name: 'receipt_2026.png',
            type: 'image/png',
            tmpName: $tempUploadFile,
            error: UPLOAD_ERR_OK,
            size: filesize($tempUploadFile) ?: 0
        );

        $storageTenant = $this->storage->forTenant(7);
        $saved = $storageTenant->store($uploadedFile, 'receipts', false);
        $fileId = (int) $saved['id'];

        // 1. Generate Signed URL valid (TTL 300 detik)
        $url = $this->storage->temporaryUrl($fileId, 300);
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $queryParams);

        $router = $this->app->getContainer()->get(Router::class);
        $controller = new FileDownloadController($this->storage, $this->db);

        // 2. Request download dengan signed URL sah
        $reqValid = new Request('GET', $url, [], $queryParams);
        $resValid = $controller->download($reqValid, (string) $fileId);
        $this->assertSame(200, $resValid->getStatusCode());
        $this->assertSame('image/png', $resValid->getHeaders()['Content-Type']);
        $this->assertSame('FAKE_PNG_BINARY_DATA', $resValid->getBody());

        // 3. Request download dengan signature palsu -> 403
        $reqTampered = new Request('GET', "/storage/download/{$fileId}", [], [
            'expires' => $queryParams['expires'] ?? 0,
            'signature' => 'tampered_fake_signature_hash',
        ]);
        $resTampered = $controller->download($reqTampered, (string) $fileId);
        $this->assertSame(403, $resTampered->getStatusCode());

        // 4. Request download kedaluwarsa -> 403
        $reqExpired = new Request('GET', "/storage/download/{$fileId}", [], [
            'expires' => time() - 100,
            'signature' => $queryParams['signature'] ?? '',
        ]);
        $resExpired = $controller->download($reqExpired, (string) $fileId);
        $this->assertSame(403, $resExpired->getStatusCode());
    }

    public function test_file_validation_rules_in_validator(): void
    {
        $tempFile = $this->tempStorageDir . '/test_doc.pdf';
        file_put_contents($tempFile, str_repeat('A', 50 * 1024)); // 50 KB

        $validPdf = new UploadedFile(
            name: 'invoice.pdf',
            type: 'application/pdf',
            tmpName: $tempFile,
            error: UPLOAD_ERR_OK,
            size: 50 * 1024
        );

        // 1. Valid PDF passes rules: file, mimes:pdf, max_kb:100
        $validator = Validator::make(
            ['attachment' => $validPdf],
            ['attachment' => 'required|file|mimes:pdf|max_kb:100']
        );
        $this->assertTrue($validator->passes());

        // 2. Mime mismatch fails: mimes:png,jpg
        $validatorFailMime = Validator::make(
            ['attachment' => $validPdf],
            ['attachment' => 'file|mimes:png,jpg']
        );
        $this->assertTrue($validatorFailMime->fails());
        $this->assertStringContainsString('ekstensi: png,jpg', $validatorFailMime->first('attachment'));

        // 3. Max size exceeded fails: max_kb:30 (file is 50 KB)
        $validatorFailSize = Validator::make(
            ['attachment' => $validPdf],
            ['attachment' => 'file|max_kb:30']
        );
        $this->assertTrue($validatorFailSize->fails());
        $this->assertStringContainsString('30 KB', $validatorFailSize->first('attachment'));
    }
}
