<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Container;
use Core\Database\Connection;
use Core\Resilience\PlatformWatchdog;
use Core\Resilience\PlatformWatchdogInterface;
use PHPUnit\Framework\TestCase;

class PlatformWatchdogTest extends TestCase
{
    private string $tempDir;
    private string $tempDbPath;
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aio_watchdog_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->tempDbPath = $this->tempDir . DIRECTORY_SEPARATOR . 'test_watchdog.sqlite';
        $this->db = new Connection("sqlite:{$this->tempDbPath}");

        // Create standard infrastructure tables
        $this->db->statement("
            CREATE TABLE jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER,
                queue TEXT NOT NULL,
                payload TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                reserved_at TEXT,
                available_at TEXT NOT NULL,
                created_at TEXT NOT NULL
            );
        ");

        $this->db->statement("
            CREATE TABLE failed_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER,
                queue TEXT NOT NULL,
                payload TEXT NOT NULL,
                exception TEXT NOT NULL,
                failed_at TEXT NOT NULL
            );
        ");

        $this->db->statement("
            CREATE TABLE circuit_breakers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                service_name TEXT NOT NULL UNIQUE,
                state TEXT NOT NULL DEFAULT 'closed',
                failure_count INTEGER NOT NULL DEFAULT 0,
                success_count INTEGER NOT NULL DEFAULT 0,
                failure_threshold INTEGER NOT NULL DEFAULT 5,
                cooldown_seconds INTEGER NOT NULL DEFAULT 60,
                last_failure_at TEXT,
                last_success_at TEXT,
                last_state_change_at TEXT NOT NULL,
                last_error TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            );
        ");

        $this->db->statement("
            CREATE TABLE locks (
                key TEXT PRIMARY KEY,
                owner TEXT NOT NULL,
                expires_at INTEGER NOT NULL,
                created_at INTEGER NOT NULL
            );
        ");
    }

    protected function tearDown(): void
    {
        $this->db->close();
        $this->deleteRecursive($this->tempDir);
        parent::tearDown();
    }

    private function deleteRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->deleteRecursive($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    public function testCleanDatabaseReports100PercentHealthy(): void
    {
        $watchdog = new PlatformWatchdog($this->db, $this->tempDbPath);

        $report = $watchdog->checkHealth();

        $this->assertTrue($report->isHealthy);
        $this->assertEquals('healthy', $report->overallStatus);
        $this->assertEquals('healthy', $report->queueHealth['status']);
        $this->assertEquals('healthy', $report->circuitHealth['status']);
        $this->assertEquals('healthy', $report->databaseHealth['status']);
        $this->assertEquals('healthy', $report->lockHealth['status']);
        $this->assertEquals('healthy', $report->dlqHealth['status']);
    }

    public function testDetectsOpenCircuitBreakers(): void
    {
        $watchdog = new PlatformWatchdog($this->db, $this->tempDbPath);
        $now = date('Y-m-d H:i:s');

        $this->db->statement("
            INSERT INTO circuit_breakers (service_name, state, failure_count, last_state_change_at, created_at, updated_at)
            VALUES ('bpjs_vclaim', 'open', 5, ?, ?, ?)
        ", [$now, $now, $now]);

        $report = $watchdog->checkHealth();

        $this->assertFalse($report->isHealthy);
        $this->assertEquals('degraded', $report->overallStatus);
        $this->assertEquals('degraded', $report->circuitHealth['status']);
        $this->assertContains('bpjs_vclaim', $report->circuitHealth['details']['open']);
    }

    public function testDetectsStaleReservedQueueJobs(): void
    {
        $watchdog = new PlatformWatchdog($this->db, $this->tempDbPath);
        $staleTime = date('Y-m-d H:i:s', time() - 300); // 5 mins ago

        $this->db->statement("
            INSERT INTO jobs (queue, payload, attempts, reserved_at, available_at, created_at)
            VALUES ('default', '{}', 1, ?, ?, ?)
        ", [$staleTime, $staleTime, $staleTime]);

        $report = $watchdog->checkHealth();

        $this->assertFalse($report->isHealthy);
        $this->assertEquals('degraded', $report->overallStatus);
        $this->assertEquals('degraded', $report->queueHealth['status']);
        $this->assertEquals(1, $report->queueHealth['details']['stale_jobs']);
    }

    public function testDetectsExcessiveFailedJobsInDlq(): void
    {
        $watchdog = new PlatformWatchdog($this->db, $this->tempDbPath);
        $now = date('Y-m-d H:i:s');

        for ($i = 0; $i < 15; $i++) {
            $this->db->statement("
                INSERT INTO failed_jobs (queue, payload, exception, failed_at)
                VALUES ('notifications', '{}', 'Timeout', ?)
            ", [$now]);
        }

        $report = $watchdog->checkHealth();

        $this->assertFalse($report->isHealthy);
        $this->assertEquals('degraded', $report->dlqHealth['status']);
    }

    public function testGlobalWatchdogHelper(): void
    {
        $container = Container::getInstance();
        $container->singleton(PlatformWatchdogInterface::class, function () {
            return new PlatformWatchdog($this->db, $this->tempDbPath);
        });

        $this->assertTrue(function_exists('watchdog'));
        $this->assertInstanceOf(PlatformWatchdogInterface::class, watchdog());
    }
}
