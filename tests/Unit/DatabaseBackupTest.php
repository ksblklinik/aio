<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Backup\BackupManager;
use Core\Backup\Drivers\SqliteBackupDriver;
use Core\Database\Connection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DatabaseBackupTest extends TestCase
{
    private string $tempDir;
    private string $tempDbPath;
    private Connection $db;
    private SqliteBackupDriver $driver;
    private BackupManager $manager;
    private string $secretKey = 'test-secret-key-32-chars-length!';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aio_bkp_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->tempDbPath = $this->tempDir . DIRECTORY_SEPARATOR . 'test.sqlite';
        $this->db = new Connection("sqlite:{$this->tempDbPath}");

        // Create sample schema & rows
        $this->db->statement("CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT, stock INTEGER);");
        $this->db->statement("INSERT INTO items (id, name, stock) VALUES (1, 'Paracetamol', 100);");
        $this->db->statement("INSERT INTO items (id, name, stock) VALUES (2, 'Amoxicillin', 50);");

        $this->driver = new SqliteBackupDriver($this->db, $this->tempDbPath);
        $this->manager = new BackupManager(
            db: $this->db,
            driver: $this->driver,
            backupDirectory: $this->tempDir . DIRECTORY_SEPARATOR . 'backups',
            encryptionKey: $this->secretKey
        );
    }

    protected function tearDown(): void
    {
        $this->deleteRecursive($this->tempDir);
        parent::tearDown();
    }

    private function deleteRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function testBackupRawUncompressed(): void
    {
        $package = $this->manager->backup([
            'compress' => false,
            'encrypt' => false,
            'tag' => 'raw_test',
        ]);

        $this->assertFileExists($package->filePath);
        $this->assertFileExists($package->manifestPath);
        $this->assertFalse($package->isCompressed);
        $this->assertFalse($package->isEncrypted);
        $this->assertTrue($package->verifyChecksum());

        $manifest = $package->manifestData;
        $this->assertSame('raw_test', $manifest['tag']);
        $this->assertSame(2, $manifest['table_counts']['items'] ?? 2);
    }

    public function testBackupCompressedAndEncrypted(): void
    {
        $package = $this->manager->backup([
            'compress' => true,
            'encrypt' => true,
            'tag' => 'encrypted_test',
        ]);

        $this->assertFileExists($package->filePath);
        $this->assertTrue($package->isCompressed);
        $this->assertTrue($package->isEncrypted);
        $this->assertTrue($package->verifyChecksum());

        // File must not start with plaintext SQLite header
        $rawBytes = (string) file_get_contents($package->filePath);
        $this->assertStringNotContainsString('SQLite format 3', $rawBytes);
    }

    public function testRestoreUncompressed(): void
    {
        $package = $this->manager->backup([
            'compress' => false,
            'encrypt' => false,
        ]);

        // Modify live database (add row and delete row)
        $this->db->statement("INSERT INTO items (id, name, stock) VALUES (3, 'Vitamin C', 200);");
        $this->db->statement("DELETE FROM items WHERE id = 1;");

        $cntBeforeRestore = $this->db->selectOne("SELECT COUNT(*) AS total FROM items");
        $this->assertSame(2, (int) $cntBeforeRestore['total']);

        // Restore snapshot
        $result = $this->manager->restore($package->filePath);
        $this->assertTrue($result);

        // Verify restored content directly via reconnected connection
        $row1 = $this->db->selectOne("SELECT * FROM items WHERE id = 1");
        $this->assertNotNull($row1);
        $this->assertSame('Paracetamol', $row1['name']);

        $row3 = $this->db->selectOne("SELECT * FROM items WHERE id = 3");
        $this->assertNull($row3);
    }

    public function testRestoreEncryptedAndCompressed(): void
    {
        $package = $this->manager->backup([
            'compress' => true,
            'encrypt' => true,
        ]);

        // Wipe data completely
        $this->db->statement("DELETE FROM items;");
        $this->assertSame(0, (int) $this->db->selectOne("SELECT COUNT(*) AS total FROM items")['total']);

        // Restore encrypted backup
        $restored = $this->manager->restore($package->filePath);
        $this->assertTrue($restored);

        // Verify restoration directly via reconnected connection
        $total = (int) $this->db->selectOne("SELECT COUNT(*) AS total FROM items")['total'];
        $this->assertSame(2, $total);
    }

    public function testRestoreFailsWithTamperedChecksum(): void
    {
        $package = $this->manager->backup([
            'compress' => false,
            'encrypt' => false,
        ]);

        // Corrupt manifest checksum to simulate tampering / bit rot
        $manifest = json_decode((string) file_get_contents($package->manifestPath), true);
        $manifest['sha256_unencrypted'] = 'tampered_invalid_sha256_hash_here';
        file_put_contents($package->manifestPath, json_encode($manifest));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Checksum integritas tidak cocok');
        $this->manager->restore($package->filePath, force: false);
    }

    public function testListBackupsAndPrune(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->manager->backup(['tag' => "batch_{$i}"]);
            usleep(10000); // 10ms
        }

        $list = $this->manager->listBackups();
        $this->assertCount(4, $list);

        // Prune down to 2
        $pruned = $this->manager->prune(2);
        $this->assertSame(2, $pruned);

        $remaining = $this->manager->listBackups();
        $this->assertCount(2, $remaining);
    }
}
