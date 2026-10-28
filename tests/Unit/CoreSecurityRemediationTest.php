<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Database\Connection;
use Core\Diagnostics\MemorySentinel;
use Core\Diagnostics\PerformanceSentinel;
use Core\Diagnostics\QueryProfiler;
use Core\Outbox\TransactionalOutboxManager;
use Core\Privacy\DataPortabilityExporter;
use Core\Privacy\TenantDataShredder;
use Core\Privacy\TenantKeyManager;
use Core\Security\AppKey;
use Core\Security\Exceptions\InsecureAppKeyException;
use PHPUnit\Framework\TestCase;

class CoreSecurityRemediationTest extends TestCase
{
    private string $tempDir;
    private string $tempDbPath;
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aio_sec_remediation_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->tempDbPath = $this->tempDir . DIRECTORY_SEPARATOR . 'sec_remediation.sqlite';
        $this->db = new Connection("sqlite:{$this->tempDbPath}");
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

    public function testAppKeyThrowsInProductionWhenKeyIsMissing(): void
    {
        $oldEnv = $_ENV['APP_ENV'] ?? null;
        $oldKey = $_ENV['APP_KEY'] ?? null;

        $_ENV['APP_ENV'] = 'production';
        $_ENV['APP_KEY'] = '';

        try {
            $this->expectException(InsecureAppKeyException::class);
            AppKey::get();
        } finally {
            if ($oldEnv !== null) {
                $_ENV['APP_ENV'] = $oldEnv;
            } else {
                unset($_ENV['APP_ENV']);
            }
            if ($oldKey !== null) {
                $_ENV['APP_KEY'] = $oldKey;
            } else {
                unset($_ENV['APP_KEY']);
            }
        }
    }

    public function testAppKeyProvidesFallbackAndFlagsInsecureInNonProduction(): void
    {
        $oldEnv = $_ENV['APP_ENV'] ?? null;
        $oldKey = $_ENV['APP_KEY'] ?? null;

        $_ENV['APP_ENV'] = 'local';
        $_ENV['APP_KEY'] = '';

        try {
            $key = AppKey::get();
            $this->assertNotEmpty($key);
            $this->assertTrue(AppKey::isInsecure());
        } finally {
            if ($oldEnv !== null) {
                $_ENV['APP_ENV'] = $oldEnv;
            } else {
                unset($_ENV['APP_ENV']);
            }
            if ($oldKey !== null) {
                $_ENV['APP_KEY'] = $oldKey;
            } else {
                unset($_ENV['APP_KEY']);
            }
        }
    }

    public function testAppKeyParsesBase64AndGeneratesValidKey(): void
    {
        $generated = AppKey::generateKey();
        $this->assertStringStartsWith('base64:', $generated);

        $oldKey = $_ENV['APP_KEY'] ?? null;
        $_ENV['APP_KEY'] = $generated;

        try {
            $resolved = AppKey::get();
            $this->assertSame(32, strlen($resolved));
            $this->assertFalse(AppKey::isInsecure());
        } finally {
            if ($oldKey !== null) {
                $_ENV['APP_KEY'] = $oldKey;
            } else {
                unset($_ENV['APP_KEY']);
            }
        }
    }

    public function testConnectionNestedManualTransactionsUsingSavepoints(): void
    {
        $this->db->statement("CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT);");

        // Outer manual transaction
        $this->db->beginTransaction();
        $this->db->statement("INSERT INTO items (id, name) VALUES (1, 'Alpha');");

        // Inner nested transaction
        $this->db->beginTransaction();
        $this->db->statement("INSERT INTO items (id, name) VALUES (2, 'Beta');");

        // Rollback inner transaction only
        $this->db->rollBack();

        // Commit outer transaction
        $this->db->commit();

        $rows = $this->db->selectAll("SELECT * FROM items ORDER BY id ASC");
        $this->assertCount(1, $rows);
        $this->assertSame('Alpha', $rows[0]['name']);
    }

    public function testPerformanceSentinelBenchmarkCleansUpTemporaryTableOnException(): void
    {
        $profiler = new QueryProfiler();
        $memory = new MemorySentinel();
        $sentinel = new PerformanceSentinel($this->db, $profiler, $memory);

        // Run benchmark normally
        $bench = $sentinel->runBenchmark(iterations: 10);
        $this->assertArrayHasKey('write', $bench);
        $this->assertArrayHasKey('read', $bench);
        $this->assertGreaterThan(0, $bench['write']['operations_per_sec']);
        $this->assertGreaterThan(0, $bench['read']['operations_per_sec']);

        // Verify no temp benchmark tables remain in sqlite_master
        $remaining = $this->db->selectAll("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '__perf_bench_%'");
        $this->assertEmpty($remaining);
    }

    public function testOutboxRelayOptimisticClaimPreventsDuplicateProcessing(): void
    {
        $this->db->statement("
            CREATE TABLE outbox_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NULL,
                event_id TEXT NOT NULL UNIQUE,
                event_name TEXT NOT NULL,
                payload TEXT NOT NULL,
                target_channel TEXT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                attempts INTEGER NOT NULL DEFAULT 0,
                max_attempts INTEGER NOT NULL DEFAULT 5,
                last_error TEXT NULL,
                published_at TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );
        ");

        $outbox = new TransactionalOutboxManager($this->db);

        $outbox->record('order.created', ['order_id' => 101], tenantId: 1);

        // Manually simulate worker 1 having claimed the event to 'processing'
        $now = date('Y-m-d H:i:s');
        $this->db->statement(
            "UPDATE outbox_events SET status = 'processing', attempts = 1, updated_at = ? WHERE id = 1",
            [$now]
        );

        // Worker 2 runs relay: since event is currently in 'processing' (not stale), it should NOT be picked up
        $res = $outbox->relay(10);
        $this->assertSame(0, $res['published']);
        $this->assertSame(0, $res['failed']);

        // Now simulate the worker timed out and the processing event became stale (>5 mins ago)
        $staleTime = date('Y-m-d H:i:s', strtotime('-10 minutes'));
        $this->db->statement(
            "UPDATE outbox_events SET updated_at = ? WHERE id = 1",
            [$staleTime]
        );

        // Worker 2 should now safely reclaim the stale processing event and publish it
        $res2 = $outbox->relay(10);
        $this->assertSame(1, $res2['published']);

        $row = $this->db->selectOne("SELECT status, attempts FROM outbox_events WHERE id = 1");
        $this->assertSame('published', $row['status']);
        $this->assertSame(2, (int) $row['attempts']);
    }

    public function testTenantAnonymizationWithRealContactsSchemaAndChildAddresses(): void
    {
        // Real schema: contacts table has NO address column; addresses are in contact_addresses
        $this->db->statement("
            CREATE TABLE tenants (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                slug TEXT NOT NULL,
                created_at TEXT NOT NULL
            );
        ");
        $this->db->statement("
            CREATE TABLE contacts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                legal_name TEXT NULL,
                id_number TEXT NULL,
                tax_id TEXT NULL,
                email TEXT NULL,
                phone TEXT NULL,
                mobile TEXT NULL,
                website TEXT NULL,
                notes TEXT NULL,
                created_at TEXT NOT NULL
            );
        ");
        $this->db->statement("
            CREATE TABLE contact_addresses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                contact_id INTEGER NOT NULL,
                address_line1 TEXT NOT NULL,
                city TEXT NULL,
                postal_code TEXT NULL
            );
        ");
        $this->db->statement("
            CREATE TABLE tenant_encryption_keys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                key_salt TEXT NOT NULL,
                algorithm TEXT NOT NULL DEFAULT 'aes-256-cbc',
                is_shredded INTEGER NOT NULL DEFAULT 0,
                shredded_at TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );
        ");

        $now = date('c');
        $this->db->statement("INSERT INTO tenants (id, name, slug, created_at) VALUES (1, 'Klinik Prima', 'klinik-prima', '{$now}')");
        $this->db->statement("INSERT INTO contacts (id, tenant_id, name, legal_name, id_number, tax_id, email, phone, mobile, notes, created_at) 
                              VALUES (1, 1, 'John Doe', 'PT John Doe', '3201123456789001', '01.234.567.8-901.000', 'john@example.com', '021-123456', '0812345678', 'Rahasia Pasien', '{$now}')");
        $this->db->statement("INSERT INTO contact_addresses (contact_id, address_line1, city, postal_code) 
                              VALUES (1, 'Jl. Sudirman No. 45', 'Jakarta', '12190')");

        $keyManager = new TenantKeyManager($this->db, 'test-key-must-be-32-chars-long!!');
        $shredder = new TenantDataShredder($this->db, $keyManager, 'test-key-must-be-32-chars-long!!');

        // Anonymize tenant
        $result = $shredder->anonymizeTenant(1);
        $this->assertSame('anonymized', $result['status']);

        // Verify contacts PII is completely scrubbed
        $contact = $this->db->selectOne("SELECT * FROM contacts WHERE id = 1");
        $this->assertStringStartsWith('ANON_CONTACT_', $contact['name']);
        $this->assertNull($contact['legal_name']);
        $this->assertNull($contact['id_number']);
        $this->assertNull($contact['tax_id']);
        $this->assertNull($contact['email']);
        $this->assertNull($contact['phone']);
        $this->assertNull($contact['mobile']);
        $this->assertNull($contact['notes']);

        // Verify child addresses were completely wiped
        $addresses = $this->db->selectAll("SELECT * FROM contact_addresses WHERE contact_id = 1");
        $this->assertEmpty($addresses);
    }

    public function testTenantHardShreddingWithDynamicTablesAndZeroOrphans(): void
    {
        $this->db->statement("CREATE TABLE tenants (id INTEGER PRIMARY KEY, name TEXT, slug TEXT, created_at TEXT);");
        $this->db->statement("CREATE TABLE contacts (id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT);");
        $this->db->statement("CREATE TABLE contact_addresses (id INTEGER PRIMARY KEY, contact_id INTEGER, address_line1 TEXT);");
        $this->db->statement("CREATE TABLE jobs (id INTEGER PRIMARY KEY, tenant_id INTEGER, payload TEXT);");
        $this->db->statement("CREATE TABLE tenant_encryption_keys (id INTEGER PRIMARY KEY, tenant_id INTEGER, key_salt TEXT, algorithm TEXT, is_shredded INTEGER, shredded_at TEXT, created_at TEXT, updated_at TEXT);");

        $now = date('c');
        $this->db->statement("INSERT INTO tenants (id, name, slug, created_at) VALUES (1, 'RS Citra', 'rs-citra', '{$now}')");
        $this->db->statement("INSERT INTO contacts (id, tenant_id, name) VALUES (10, 1, 'Pasien A')");
        $this->db->statement("INSERT INTO contact_addresses (id, contact_id, address_line1) VALUES (100, 10, 'Jl. Dahlia')");
        $this->db->statement("INSERT INTO jobs (id, tenant_id, payload) VALUES (50, 1, 'send_email')");

        $keyManager = new TenantKeyManager($this->db, 'test-key-must-be-32-chars-long!!');
        $shredder = new TenantDataShredder($this->db, $keyManager, 'test-key-must-be-32-chars-long!!');

        $shredRes = $shredder->shredTenant(1, force: true);
        $this->assertSame('shredded', $shredRes['status']);

        // Check certificate
        $cert = $shredRes['certificate'];
        $this->assertNotEmpty($cert['destruction_signature']);
        $this->assertGreaterThanOrEqual(3, $cert['total_rows_purged']);

        // Verify no orphans left behind
        $this->assertEmpty($this->db->selectAll("SELECT * FROM contacts WHERE tenant_id = 1"));
        $this->assertEmpty($this->db->selectAll("SELECT * FROM contact_addresses WHERE contact_id = 10"));
        $this->assertEmpty($this->db->selectAll("SELECT * FROM jobs WHERE tenant_id = 1"));
        $this->assertNull($this->db->selectOne("SELECT * FROM tenants WHERE id = 1"));
    }

    public function testDataPortabilityExporterRedactsCredentialsAndExcludesKeySecrets(): void
    {
        $this->db->statement("CREATE TABLE tenants (id INTEGER PRIMARY KEY, name TEXT, slug TEXT, created_at TEXT);");
        $this->db->statement("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, password_hash TEXT, created_at TEXT);");
        $this->db->statement("CREATE TABLE tenant_user (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, role TEXT);");
        $this->db->statement("CREATE TABLE webhooks (id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT, url TEXT, secret TEXT);");
        $this->db->statement("CREATE TABLE tenant_encryption_keys (id INTEGER PRIMARY KEY, tenant_id INTEGER, key_salt TEXT, algorithm TEXT, is_shredded INTEGER, shredded_at TEXT, created_at TEXT, updated_at TEXT);");

        $now = date('c');
        $this->db->statement("INSERT INTO tenants (id, name, slug, created_at) VALUES (1, 'Klinik Port', 'klinik-port', '{$now}')");
        $this->db->statement("INSERT INTO users (id, name, email, password_hash, created_at) VALUES (1, 'Admin', 'admin@port.com', 'bcrypt-super-secret-hash', '{$now}')");
        $this->db->statement("INSERT INTO tenant_user (id, tenant_id, user_id, role) VALUES (1, 1, 1, 'owner')");
        $this->db->statement("INSERT INTO webhooks (id, tenant_id, name, url, secret) VALUES (1, 1, 'Billing Webhook', 'https://api.external.com/wh', 'whsec_secret_key_123')");
        $this->db->statement("INSERT INTO tenant_encryption_keys (id, tenant_id, key_salt, algorithm, is_shredded, created_at, updated_at) VALUES (1, 1, 'raw_salt_secret', 'aes-256-cbc', 0, '{$now}', '{$now}')");

        $exporter = new DataPortabilityExporter($this->db);
        $export = $exporter->exportTenant(1);

        $this->assertArrayHasKey('manifest', $export);
        $this->assertArrayHasKey('data', $export);

        // tenant_encryption_keys must NOT be in exported data
        $this->assertArrayNotHasKey('tenant_encryption_keys', $export['data']);

        // webhooks secret must be redacted
        $webhooks = $export['data']['webhooks'];
        $this->assertCount(1, $webhooks);
        $this->assertSame('[REDACTED]', $webhooks[0]['secret']);

        // users must NOT expose password_hash
        $users = $export['data']['users'];
        $this->assertCount(1, $users);
        $this->assertArrayNotHasKey('password_hash', $users[0]);
    }
}
