<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Container;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Lock\Drivers\DatabaseLockDriver;
use Core\Lock\Drivers\FileLockDriver;
use Core\Lock\LockInterface;
use Core\Lock\LockManager;
use Core\Lock\LockManagerInterface;
use Core\Lock\LockTimeoutException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LockEngineTest extends TestCase
{
    private Connection $db;
    private DatabaseLockDriver $dbDriver;
    private LockManager $dbLockManager;
    private string $tempLockDir;
    private FileLockDriver $fileDriver;
    private LockManager $fileLockManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new Connection('sqlite::memory:');
        $migrator = new Migrator($this->db);
        $migrator->runMigrationsFrom(__DIR__ . '/../../database/migrations');

        $this->dbDriver = new DatabaseLockDriver($this->db);
        $this->dbLockManager = new LockManager($this->dbDriver);

        $this->tempLockDir = sys_get_temp_dir() . '/aio_test_locks_' . uniqid();
        $this->fileDriver = new FileLockDriver($this->tempLockDir);
        $this->fileLockManager = new LockManager($this->fileDriver);

        $container = Container::getInstance();
        $container->instance(Connection::class, $this->db);
        $container->instance(LockManagerInterface::class, $this->dbLockManager);
    }

    protected function tearDown(): void
    {
        // Clean up temp file locks
        if (is_dir($this->tempLockDir)) {
            $files = glob($this->tempLockDir . '/*');
            if ($files) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
            @rmdir($this->tempLockDir);
        }

        parent::tearDown();
    }

    public function testAcquireAndReleaseWithDatabaseDriver(): void
    {
        $lock = $this->dbLockManager->lock('inventory:item:100', ttlSeconds: 10, owner: 'worker-1');
        $this->assertTrue($lock->acquire());
        $this->assertTrue($lock->isAcquired());
        $this->assertTrue($this->dbLockManager->isLocked('inventory:item:100'));

        $this->assertTrue($lock->release());
        $this->assertFalse($lock->isAcquired());
        $this->assertFalse($this->dbLockManager->isLocked('inventory:item:100'));
    }

    public function testMutualExclusion(): void
    {
        $lock1 = $this->dbLockManager->lock('order:process:42', ttlSeconds: 10, owner: 'thread-1');
        $lock2 = $this->dbLockManager->lock('order:process:42', ttlSeconds: 10, owner: 'thread-2');

        $this->assertTrue($lock1->acquire(waitTimeoutMs: 0));
        $this->assertFalse($lock2->acquire(waitTimeoutMs: 0));

        $lock1->release();

        $this->assertTrue($lock2->acquire(waitTimeoutMs: 0));
        $lock2->release();
    }

    public function testWaitTimeoutSuccess(): void
    {
        $lock1 = $this->dbLockManager->lock('sequence:invoice', ttlSeconds: 5, owner: 'p1');
        $lock2 = $this->dbLockManager->lock('sequence:invoice', ttlSeconds: 5, owner: 'p2');

        $this->assertTrue($lock1->acquire());

        // Simulasi release di background setelah 30ms menggunakan thread simulasi
        usleep(30000); // 30ms
        $lock1->release();

        // Lock 2 harus berhasil karena menunggu hingga 200ms
        $this->assertTrue($lock2->acquire(waitTimeoutMs: 200));
        $lock2->release();
    }

    public function testWaitTimeoutFailure(): void
    {
        $lock1 = $this->dbLockManager->lock('queue:ticket:A', ttlSeconds: 10, owner: 'p1');
        $lock2 = $this->dbLockManager->lock('queue:ticket:A', ttlSeconds: 10, owner: 'p2');

        $this->assertTrue($lock1->acquire());

        // Lock 2 hanya mau menunggu 50ms, padahal lock 1 belum dilepas
        $this->assertFalse($lock2->acquire(waitTimeoutMs: 50));

        $lock1->release();
    }

    public function testTtlAutoExpiration(): void
    {
        // Set TTL 1 detik
        $lock1 = $this->dbLockManager->lock('batch:fefo:99', ttlSeconds: 1, owner: 'crashed-worker');
        $this->assertTrue($lock1->acquire());

        // Tidur 1.1 detik agar lock kedaluwarsa secara alami
        usleep(1100000);

        // Lock 2 harus bisa mengambil alih karena lock 1 sudah kedaluwarsa
        $lock2 = $this->dbLockManager->lock('batch:fefo:99', ttlSeconds: 10, owner: 'fresh-worker');
        $this->assertTrue($lock2->acquire(waitTimeoutMs: 0));
        $lock2->release();
    }

    public function testOwnerSafety(): void
    {
        $lockA = $this->dbLockManager->lock('resource:x', ttlSeconds: 10, owner: 'owner-A');
        $this->assertTrue($lockA->acquire());

        // Pihak B mencoba merilis lock pihak A
        $this->assertFalse($this->dbLockManager->release('resource:x', owner: 'owner-B'));

        // Lock tetap terkunci
        $this->assertTrue($this->dbLockManager->isLocked('resource:x'));

        // Owner A yang sah bisa merilis
        $this->assertTrue($this->dbLockManager->release('resource:x', owner: 'owner-A'));
        $this->assertFalse($this->dbLockManager->isLocked('resource:x'));
    }

    public function testLockRunClosureReturnsValueAndReleases(): void
    {
        $result = $this->dbLockManager->run('calc:total', function () {
            $this->assertTrue($this->dbLockManager->isLocked('calc:total'));
            return 42 * 2;
        });

        $this->assertEquals(84, $result);
        $this->assertFalse($this->dbLockManager->isLocked('calc:total'));
    }

    public function testLockRunClosureReleasesOnException(): void
    {
        $caught = false;
        try {
            $this->dbLockManager->run('calc:error', function () {
                throw new RuntimeException("Kesalahan di dalam lock.");
            });
        } catch (RuntimeException $e) {
            $caught = true;
            $this->assertEquals("Kesalahan di dalam lock.", $e->getMessage());
        }

        $this->assertTrue($caught);
        $this->assertFalse($this->dbLockManager->isLocked('calc:error'));
    }

    public function testFileLockDriverMutualExclusion(): void
    {
        $lock1 = $this->fileLockManager->lock('file:doc:1', ttlSeconds: 5, owner: 'proc-1');
        $lock2 = $this->fileLockManager->lock('file:doc:1', ttlSeconds: 5, owner: 'proc-2');

        $this->assertTrue($lock1->acquire(waitTimeoutMs: 0));
        $this->assertFalse($lock2->acquire(waitTimeoutMs: 0));

        $lock1->release();

        $this->assertTrue($lock2->acquire(waitTimeoutMs: 0));
        $lock2->release();
    }

    public function testPruneExpiredLocks(): void
    {
        // Masukkan lock manual yang sudah kedaluwarsa 10 detik lalu
        $past = time() - 10;
        $this->db->statement(
            "INSERT INTO locks (\"key\", \"owner\", \"expires_at\", \"created_at\") VALUES (?, ?, ?, ?)",
            ['expired:1', 'old', $past, date('c')]
        );

        $this->dbLockManager->acquire('fresh:1', ttlSeconds: 60);

        $pruned = $this->dbLockManager->pruneExpired();
        $this->assertEquals(1, $pruned);

        $active = $this->dbLockManager->listActiveLocks();
        $this->assertCount(1, $active);
        $this->assertEquals('fresh:1', $active[0]['key']);
    }

    public function testGlobalHelperLock(): void
    {
        $helperLock = lock('global:helper:test', ttlSeconds: 5);
        $this->assertInstanceOf(LockInterface::class, $helperLock);
        $this->assertTrue($helperLock->acquire());
        $helperLock->release();

        $helperManager = lock();
        $this->assertInstanceOf(LockManagerInterface::class, $helperManager);
    }
}
