<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Container;
use Core\Database\Connection;
use Core\Privacy\DataPortabilityExporter;
use Core\Privacy\Exceptions\TenantKeyShreddedException;
use Core\Privacy\TenantDataShredder;
use Core\Privacy\TenantKeyManager;
use Core\Privacy\TenantPrivacyManager;
use Core\Privacy\TenantPrivacyManagerInterface;
use PHPUnit\Framework\TestCase;

class TenantCryptoShreddingTest extends TestCase
{
    private string $tempDir;
    private string $tempDbPath;
    private Connection $db;
    private TenantPrivacyManager $privacy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aio_privacy_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->tempDbPath = $this->tempDir . DIRECTORY_SEPARATOR . 'test_privacy.sqlite';
        $this->db = new Connection("sqlite:{$this->tempDbPath}");

        // Create required tables
        $this->db->statement("
            CREATE TABLE tenants (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                slug TEXT NOT NULL,
                domain TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );
        ");

        $this->db->statement("
            CREATE TABLE tenant_encryption_keys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL UNIQUE,
                key_salt TEXT NOT NULL,
                algorithm TEXT NOT NULL DEFAULT 'aes-256-cbc',
                is_shredded INTEGER NOT NULL DEFAULT 0,
                shredded_at TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );
        ");

        $this->db->statement("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL,
                password_hash TEXT NOT NULL,
                is_superadmin INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );
        ");

        $this->db->statement("
            CREATE TABLE tenant_user (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                role TEXT NOT NULL DEFAULT 'member'
            );
        ");

        $this->db->statement("
            CREATE TABLE contacts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                email TEXT NULL,
                phone TEXT NULL,
                address TEXT NULL,
                notes TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );
        ");

        $this->db->statement("
            CREATE TABLE appointments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                notes TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );
        ");

        $this->db->statement("
            CREATE TABLE invoices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                invoice_number TEXT NOT NULL,
                notes TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );
        ");

        $this->db->statement("
            CREATE TABLE audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NULL,
                action TEXT NOT NULL,
                created_at TEXT NOT NULL
            );
        ");

        // Seed 2 tenants
        $now = date('c');
        $this->db->statement("INSERT INTO tenants (id, name, slug, created_at, updated_at) VALUES (1, 'Klinik Sehat Utama', 'klinik-sehat', '{$now}', '{$now}')");
        $this->db->statement("INSERT INTO tenants (id, name, slug, created_at, updated_at) VALUES (2, 'RS Medika Nusantara', 'rs-medika', '{$now}', '{$now}')");

        $keyManager = new TenantKeyManager($this->db, 'test-master-secret-app-key-32chars!!');
        $exporter = new DataPortabilityExporter($this->db);
        $shredder = new TenantDataShredder($this->db, $keyManager, 'test-master-secret-app-key-32chars!!');

        $this->privacy = new TenantPrivacyManager($keyManager, $exporter, $shredder);

        // Bind into container
        $container = Container::getInstance();
        $container->singleton(TenantPrivacyManagerInterface::class, fn() => $this->privacy);
        $container->singleton(Connection::class, fn() => $this->db);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempDbPath)) {
            @unlink($this->tempDbPath);
        }
        if (is_dir($this->tempDir)) {
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function testTenantKeyDerivationAndCryptoIsolation(): void
    {
        $keyManager = $this->privacy->getKeyManager();

        $salt1 = $keyManager->getOrCreateKeySalt(1);
        $salt2 = $keyManager->getOrCreateKeySalt(2);

        $this->assertNotEmpty($salt1);
        $this->assertNotEmpty($salt2);
        $this->assertNotEquals($salt1, $salt2, "Setiap tenant wajib memiliki cryptographic salt yang berbeda");

        $key1 = $keyManager->deriveKey(1);
        $key2 = $keyManager->deriveKey(2);
        $this->assertNotEquals($key1, $key2, "Derived key antar-tenant harus terisolasi total");

        // Enkripsi dengan Tenant 1 tidak boleh bisa didekripsi dengan Tenant 2
        $plaintext = "Catatan Diagnosa Medis Pasien Rahasia";
        $ciphertext = $this->privacy->encrypt(1, $plaintext);
        $this->assertNotEquals($plaintext, $ciphertext);

        // Tenant 1 can decrypt
        $decrypted1 = $this->privacy->decrypt(1, $ciphertext);
        $this->assertSame($plaintext, $decrypted1);

        // Tenant 2 cannot decrypt Tenant 1's payload (returns null)
        $decrypted2 = $this->privacy->decrypt(2, $ciphertext);
        $this->assertNull($decrypted2, "Tenant 2 dilarang dapat mendekripsi ciphertext milik Tenant 1");
    }

    public function testInstantCryptoShreddingRendersPastDataPermanentlyUnrecoverable(): void
    {
        $plaintext = "Informasi Rekam Medis & Kartu Kredit Pasien";
        $ciphertext = $this->privacy->encrypt(1, $plaintext);

        // Sebelum shred, payload terbaca sempurna
        $this->assertSame($plaintext, $this->privacy->decrypt(1, $ciphertext));
        $this->assertFalse($this->privacy->getKeyManager()->isKeyShredded(1));

        // Eksekusi Crypto-Shredding
        $shredResult = $this->privacy->shredKey(1);
        $this->assertTrue($shredResult);
        $this->assertTrue($this->privacy->getKeyManager()->isKeyShredded(1));

        // Setelah shred, ciphertext masa lalu matematis tidak dapat didekripsi (returns null)
        $decryptedAfterShred = $this->privacy->decrypt(1, $ciphertext);
        $this->assertNull($decryptedAfterShred, "Setelah crypto-shredding, ciphertext harus matematis tidak terbaca");

        // Enkripsi baru untuk tenant tersebut harus ditolak dengan TenantKeyShreddedException
        $this->expectException(TenantKeyShreddedException::class);
        $this->privacy->encrypt(1, "Data Baru");
    }

    public function testDataPortabilityExportGeneratesDeterministicEnvelopeAndSha256Checksum(): void
    {
        $now = date('c');

        // Seed data untuk tenant 1
        $this->db->statement("INSERT INTO users (id, name, email, password_hash, created_at, updated_at) VALUES (10, 'Dr. Budi', 'budi@sehat.com', 'secret', '{$now}', '{$now}')");
        $this->db->statement("INSERT INTO tenant_user (tenant_id, user_id, role) VALUES (1, 10, 'admin')");
        $this->db->statement("INSERT INTO contacts (tenant_id, name, email, phone, created_at, updated_at) VALUES (1, 'Pasien Alpha', 'alpha@gmail.com', '08123456789', '{$now}', '{$now}')");
        $this->db->statement("INSERT INTO invoices (tenant_id, invoice_number, notes, created_at, updated_at) VALUES (1, 'INV/2026/001', 'Faktur Konsultasi', '{$now}', '{$now}')");

        $exportFile = $this->tempDir . DIRECTORY_SEPARATOR . 'tenant_1_export.json';
        $export = $this->privacy->exportTenant(1, ['output_file' => $exportFile]);

        $this->assertArrayHasKey('manifest', $export);
        $this->assertArrayHasKey('data', $export);

        $manifest = $export['manifest'];
        $this->assertSame(1, $manifest['tenant_id']);
        $this->assertSame('Klinik Sehat Utama', $manifest['tenant_name']);
        $this->assertNotEmpty($manifest['checksum_sha256']);
        $this->assertFileExists($exportFile);

        // Verifikasi integritas file yang ditulis ke disk
        $fileContent = (string) file_get_contents($exportFile);
        $this->assertSame($manifest['checksum_sha256'], hash('sha256', $fileContent));

        $data = $export['data'];
        $this->assertCount(1, $data['users']);
        $this->assertSame('Dr. Budi', $data['users'][0]['name']);
        $this->assertCount(1, $data['contacts']);
        $this->assertSame('Pasien Alpha', $data['contacts'][0]['name']);
        $this->assertCount(1, $data['invoices']);
        $this->assertSame('INV/2026/001', $data['invoices'][0]['invoice_number']);
    }

    public function testTenantAnonymizationPreservesStructureAndShredsPiiAndCryptoKey(): void
    {
        $now = date('c');

        // Seed tenant 1 contact and exclusive user
        $this->db->statement("INSERT INTO users (id, name, email, password_hash, is_superadmin, created_at, updated_at) VALUES (20, 'Suster Siti', 'siti@sehat.com', 'secret', 0, '{$now}', '{$now}')");
        $this->db->statement("INSERT INTO tenant_user (tenant_id, user_id, role) VALUES (1, 20, 'staff')");
        $this->db->statement("INSERT INTO contacts (tenant_id, name, email, phone, address, notes, created_at, updated_at) VALUES (1, 'Pasien Rahasia', 'rahasia@gmail.com', '08999999', 'Jl. Merdeka No 1', 'Catatan medis pribadi', '{$now}', '{$now}')");
        $this->db->statement("INSERT INTO appointments (tenant_id, notes, created_at, updated_at) VALUES (1, 'Keluhan demam berdarah', '{$now}', '{$now}')");
        $this->db->statement("INSERT INTO invoices (tenant_id, invoice_number, notes, created_at, updated_at) VALUES (1, 'INV/2026/002', 'Faktur Rawat Inap', '{$now}', '{$now}')");

        // Buat kunci kriptografis aktif
        $this->privacy->encrypt(1, "Data sebelum anonimisasi");

        // Jalankan anonimisasi (GDPR / UU PDP Right to Erasure)
        $report = $this->privacy->anonymizeTenant(1, 'Permintaan Hak untuk Dilupakan Pasien');

        $this->assertSame('anonymized', $report['status']);
        $this->assertTrue($report['crypto_key_shredded']);

        // Periksa kontak telah di-anonimkan (PII lenyap)
        $contact = $this->db->selectOne("SELECT * FROM contacts WHERE tenant_id = 1");
        $this->assertStringStartsWith('ANON_CONTACT_', $contact['name']);
        $this->assertNull($contact['email']);
        $this->assertNull($contact['phone']);
        $this->assertNull($contact['address']);
        $this->assertNull($contact['notes']);

        // Periksa user eksklusif telah di-anonimkan
        $user = $this->db->selectOne("SELECT * FROM users WHERE id = 20");
        $this->assertStringStartsWith('ANON_USER_', $user['name']);
        $this->assertStringEndsWith('@shredded.local', $user['email']);
        $this->assertSame('', $user['password_hash']);

        // Periksa appointments & invoices notes dibersihkan
        $appointment = $this->db->selectOne("SELECT * FROM appointments WHERE tenant_id = 1");
        $this->assertNull($appointment['notes']);
        $invoice = $this->db->selectOne("SELECT * FROM invoices WHERE tenant_id = 1");
        $this->assertNull($invoice['notes']);

        // Kunci kriptografis harus berstatus shredded
        $this->assertTrue($this->privacy->getKeyManager()->isKeyShredded(1));
    }

    public function testTenantHardShreddingPermanentlyPurgesAllRecordsAndGeneratesCertificate(): void
    {
        $now = date('c');

        // Seed data pada tenant 1
        $this->db->statement("INSERT INTO contacts (tenant_id, name, email, created_at, updated_at) VALUES (1, 'Vendor Medis', 'vendor@med.com', '{$now}', '{$now}')");
        $this->db->statement("INSERT INTO appointments (tenant_id, notes, created_at, updated_at) VALUES (1, 'Jadwal Kontrol', '{$now}', '{$now}')");
        $this->db->statement("INSERT INTO invoices (tenant_id, invoice_number, created_at, updated_at) VALUES (1, 'INV/001', '{$now}', '{$now}')");

        // Verifikasi catatan ada sebelum shred
        $this->assertNotEmpty($this->db->select("SELECT * FROM contacts WHERE tenant_id = 1"));
        $this->assertNotEmpty($this->db->select("SELECT * FROM appointments WHERE tenant_id = 1"));
        $this->assertNotEmpty($this->db->select("SELECT * FROM invoices WHERE tenant_id = 1"));

        // Shred tanpa force harus gagal
        try {
            $this->privacy->shredTenant(1, force: false);
            $this->fail("Shred tanpa force seharusnya melempar exception");
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("Force confirmation is required", $e->getMessage());
        }

        // Shred dengan force = true
        $result = $this->privacy->shredTenant(1, force: true, reason: 'Penutupan Operasional Tenant');

        $this->assertSame('shredded', $result['status']);
        $cert = $result['certificate'];
        $this->assertNotEmpty($cert['certificate_id']);
        $this->assertNotEmpty($cert['destruction_signature']);
        $this->assertGreaterThanOrEqual(3, $cert['total_rows_purged']);

        // Pastikan seluruh record tenant 1 lenyap dari database
        $this->assertEmpty($this->db->select("SELECT * FROM contacts WHERE tenant_id = 1"));
        $this->assertEmpty($this->db->select("SELECT * FROM appointments WHERE tenant_id = 1"));
        $this->assertEmpty($this->db->select("SELECT * FROM invoices WHERE tenant_id = 1"));
        $this->assertNull($this->db->selectOne("SELECT * FROM tenants WHERE id = 1"));

        // Tenant 2 harus tetap utuh dan tidak tersentuh
        $this->assertNotNull($this->db->selectOne("SELECT * FROM tenants WHERE id = 2"));
    }

    public function testGlobalPrivacyHelper(): void
    {
        $helper = privacy();
        $this->assertInstanceOf(TenantPrivacyManagerInterface::class, $helper);
        $this->assertInstanceOf(TenantKeyManager::class, $helper->getKeyManager());
        $this->assertInstanceOf(DataPortabilityExporter::class, $helper->getExporter());
        $this->assertInstanceOf(TenantDataShredder::class, $helper->getShredder());
    }
}
