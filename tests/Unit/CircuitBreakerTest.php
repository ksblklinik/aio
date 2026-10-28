<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Database\Connection;
use Core\Resilience\CircuitBreakerInterface;
use Core\Resilience\DatabaseCircuitBreaker;
use Core\Resilience\Exceptions\CircuitBreakerOpenException;
use Exception;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CircuitBreakerTest extends TestCase
{
    private string $tempDir;
    private string $tempDbPath;
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aio_cb_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->tempDbPath = $this->tempDir . DIRECTORY_SEPARATOR . 'test_cb.sqlite';
        $this->db = new Connection("sqlite:{$this->tempDbPath}");

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

    public function testInitialStateIsClosed(): void
    {
        $cb = new DatabaseCircuitBreaker($this->db, 'sms_gateway', failureThreshold: 3, cooldownSeconds: 2);

        $this->assertEquals(CircuitBreakerInterface::STATE_CLOSED, $cb->getState());
        $this->assertTrue($cb->isAvailable());

        $result = $cb->execute(fn() => 'success');
        $this->assertEquals('success', $result);
    }

    public function testConsecutiveFailuresTripCircuitToOpen(): void
    {
        $cb = new DatabaseCircuitBreaker($this->db, 'satusehat_api', failureThreshold: 3, cooldownSeconds: 2);

        // Failure 1
        try {
            $cb->execute(fn() => throw new RuntimeException('Connection timeout 1'));
        } catch (Exception) {}
        $this->assertEquals(CircuitBreakerInterface::STATE_CLOSED, $cb->getState());
        $this->assertEquals(1, $cb->getStats()['failure_count']);

        // Failure 2
        try {
            $cb->execute(fn() => throw new RuntimeException('Connection timeout 2'));
        } catch (Exception) {}
        $this->assertEquals(CircuitBreakerInterface::STATE_CLOSED, $cb->getState());
        $this->assertEquals(2, $cb->getStats()['failure_count']);

        // Failure 3: Reaches threshold, trips to OPEN
        try {
            $cb->execute(fn() => throw new RuntimeException('Connection timeout 3'));
        } catch (Exception) {}
        $this->assertEquals(CircuitBreakerInterface::STATE_OPEN, $cb->getState());
        $this->assertFalse($cb->isAvailable());

        // Subsequent call must fail-fast immediately with CircuitBreakerOpenException
        $this->expectException(CircuitBreakerOpenException::class);
        $cb->execute(function () {
            $this->fail('Action should not have been called when circuit is OPEN!');
        });
    }

    public function testFallbackExecutionWhenOpen(): void
    {
        $cb = new DatabaseCircuitBreaker($this->db, 'whatsapp_gw', failureThreshold: 2, cooldownSeconds: 10);

        // Force open
        for ($i = 0; $i < 2; $i++) {
            try {
                $cb->execute(fn() => throw new RuntimeException('GW Down'));
            } catch (Exception) {}
        }
        $this->assertEquals(CircuitBreakerInterface::STATE_OPEN, $cb->getState());

        // Call with fallback
        $result = $cb->execute(
            action: fn() => 'Live Result',
            fallback: fn(\Throwable $e) => 'Fallback Cached Response'
        );

        $this->assertEquals('Fallback Cached Response', $result);
    }

    public function testHalfOpenStateAndRecoveryAfterCooldown(): void
    {
        $cb = new DatabaseCircuitBreaker($this->db, 'payment_gateway', failureThreshold: 2, cooldownSeconds: 1, successThreshold: 2);

        // 1. Trip to open
        for ($i = 0; $i < 2; $i++) {
            try {
                $cb->execute(fn() => throw new RuntimeException('500 Server Error'));
            } catch (Exception) {}
        }
        $this->assertEquals(CircuitBreakerInterface::STATE_OPEN, $cb->getState());

        // 2. Wait 1.1s for cooldown to elapse
        usleep(1100000);

        // 3. Status should now be HALF_OPEN
        $this->assertEquals(CircuitBreakerInterface::STATE_HALF_OPEN, $cb->getState());
        $this->assertTrue($cb->isAvailable());

        // 4. Probe success 1
        $res1 = $cb->execute(fn() => 'probe 1 ok');
        $this->assertEquals('probe 1 ok', $res1);
        $this->assertEquals(CircuitBreakerInterface::STATE_HALF_OPEN, $cb->getState());

        // 5. Probe success 2: reaches successThreshold, circuit closes!
        $res2 = $cb->execute(fn() => 'probe 2 ok');
        $this->assertEquals('probe 2 ok', $res2);
        $this->assertEquals(CircuitBreakerInterface::STATE_CLOSED, $cb->getState());
        $this->assertEquals(0, $cb->getStats()['failure_count']);
    }

    public function testHalfOpenFailureTripsImmediatelyBackToOpen(): void
    {
        $cb = new DatabaseCircuitBreaker($this->db, 'lab_device', failureThreshold: 2, cooldownSeconds: 1);

        for ($i = 0; $i < 2; $i++) {
            try {
                $cb->execute(fn() => throw new RuntimeException('Port closed'));
            } catch (Exception) {}
        }
        $this->assertEquals(CircuitBreakerInterface::STATE_OPEN, $cb->getState());

        // Wait cooldown
        usleep(1100000);
        $this->assertEquals(CircuitBreakerInterface::STATE_HALF_OPEN, $cb->getState());

        // Probe fails!
        try {
            $cb->execute(fn() => throw new RuntimeException('Port still closed'));
        } catch (Exception) {}

        // Should trip directly back to OPEN without waiting for multiple failures
        $this->assertEquals(CircuitBreakerInterface::STATE_OPEN, $cb->getState());
    }

    public function testManualReset(): void
    {
        $cb = new DatabaseCircuitBreaker($this->db, 'external_storage', failureThreshold: 1, cooldownSeconds: 300);

        try {
            $cb->execute(fn() => throw new RuntimeException('Disk detached'));
        } catch (Exception) {}
        $this->assertEquals(CircuitBreakerInterface::STATE_OPEN, $cb->getState());

        $cb->reset();
        $this->assertEquals(CircuitBreakerInterface::STATE_CLOSED, $cb->getState());
        $this->assertTrue($cb->isAvailable());
    }
}
