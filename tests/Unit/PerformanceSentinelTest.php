<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Container;
use Core\Database\Connection;
use Core\Diagnostics\MemorySentinel;
use Core\Diagnostics\PerformanceSentinel;
use Core\Diagnostics\PerformanceSentinelInterface;
use Core\Diagnostics\QueryProfiler;
use Tests\TestCase;

class PerformanceSentinelTest extends TestCase
{
    private string $tempDir;
    private string $tempDbPath;
    private Connection $db;
    private QueryProfiler $profiler;
    private MemorySentinel $memory;
    private PerformanceSentinel $sentinel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aio_sentinel_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->tempDbPath = $this->tempDir . DIRECTORY_SEPARATOR . 'test_sentinel.sqlite';
        $this->db = new Connection("sqlite:{$this->tempDbPath}");

        $this->profiler = new QueryProfiler(
            slowThresholdMs: 15.0,
            storagePath: $this->tempDir . DIRECTORY_SEPARATOR . 'slow_queries.json'
        );
        $this->memory = new MemorySentinel(warningThresholdBytes: 67108864);
        $this->sentinel = new PerformanceSentinel($this->db, $this->profiler, $this->memory);

        // Bind connection with profiler
        $this->db->setProfiler($this->profiler);

        // Bind into container
        $container = Container::getInstance();
        $container->singleton(PerformanceSentinelInterface::class, fn() => $this->sentinel);
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

    public function testQueryProfilerRecordsTimingsAndCatchesSlowQueries(): void
    {
        $this->profiler->setSlowThreshold(10.0);
        $this->assertSame(10.0, $this->profiler->getSlowThreshold());

        // Fast query (< 10ms)
        $this->profiler->record("SELECT 1", [], 2.5);

        $metrics = $this->profiler->getMetrics();
        $this->assertSame(1, $metrics['total_queries']);
        $this->assertSame(0, $metrics['slow_queries_count']);
        $this->assertSame(2.5, $metrics['total_duration_ms']);
        $this->assertSame(2.5, $metrics['avg_duration_ms']);
        $this->assertEmpty($this->profiler->getSlowQueries());

        // Slow query (>= 10ms)
        $this->profiler->record("SELECT * FROM large_table WHERE name LIKE '%test%'", ['%test%'], 35.8);

        $metrics2 = $this->profiler->getMetrics();
        $this->assertSame(2, $metrics2['total_queries']);
        $this->assertSame(1, $metrics2['slow_queries_count']);
        $this->assertSame(38.3, $metrics2['total_duration_ms']);
        $this->assertSame(35.8, $metrics2['max_duration_ms']);

        $slowQueries = $this->profiler->getSlowQueries();
        $this->assertCount(1, $slowQueries);
        $this->assertSame("SELECT * FROM large_table WHERE name LIKE '%test%'", $slowQueries[0]['sql']);
        $this->assertSame(35.8, $slowQueries[0]['duration_ms']);

        // Test buffer reset
        $this->profiler->reset();
        $resetMetrics = $this->profiler->getMetrics();
        $this->assertSame(0, $resetMetrics['total_queries']);
        $this->assertEmpty($this->profiler->getSlowQueries());
    }

    public function testMemorySentinelTracksUsageLimitsAndWarningThreshold(): void
    {
        $current = $this->memory->getCurrentUsage();
        $peak = $this->memory->getPeakUsage();

        $this->assertGreaterThan(0, $current);
        $this->assertGreaterThanOrEqual($current, $peak);

        // Warning threshold high
        $this->memory->setWarningThreshold(1073741824); // 1 GB
        $this->assertFalse($this->memory->isThresholdExceeded());

        // Warning threshold low
        $this->memory->setWarningThreshold(1024); // 1 KB
        $this->assertTrue($this->memory->isThresholdExceeded());

        // Byte formatting
        $this->assertSame('1 MB', $this->memory->formatBytes(1048576));
        $this->assertSame('500 KB', $this->memory->formatBytes(512000));
        $this->assertSame('1.5 GB', $this->memory->formatBytes((int) (1.5 * 1073741824)));

        $metrics = $this->memory->getMetrics();
        $this->assertArrayHasKey('current_usage_formatted', $metrics);
        $this->assertArrayHasKey('peak_usage_formatted', $metrics);
        $this->assertArrayHasKey('limit_formatted', $metrics);
        $this->assertTrue($metrics['is_threshold_exceeded']);
    }

    public function testConnectionProfilerIntegrationAutomaticallyRecordsQueries(): void
    {
        $this->db->statement("CREATE TABLE test_items (id INTEGER PRIMARY KEY, name TEXT);");
        $this->db->statement("INSERT INTO test_items (name) VALUES ('Item A');");
        $this->db->selectOne("SELECT * FROM test_items WHERE id = 1");
        $this->db->selectAll("SELECT * FROM test_items");
        $this->db->affectingStatement("UPDATE test_items SET name = 'Item Updated' WHERE id = 1");
        $this->db->exec("DELETE FROM test_items WHERE id = 1");

        $metrics = $this->profiler->getMetrics();
        $this->assertGreaterThanOrEqual(6, $metrics['total_queries']);
        $this->assertGreaterThan(0, $metrics['total_duration_ms']);
    }

    public function testPerformanceSentinelMetricsAndBenchmark(): void
    {
        $metrics = $this->sentinel->getMetrics();

        $this->assertArrayHasKey('timestamp', $metrics);
        $this->assertArrayHasKey('php_version', $metrics);
        $this->assertArrayHasKey('database', $metrics);
        $this->assertArrayHasKey('queries', $metrics);
        $this->assertArrayHasKey('memory', $metrics);

        // Run SQLite WAL micro-benchmark
        $bench = $this->sentinel->runBenchmark(iterations: 25);

        $this->assertSame(25, $bench['iterations']);
        $this->assertArrayHasKey('write', $bench);
        $this->assertArrayHasKey('read', $bench);
        $this->assertGreaterThan(0, $bench['write']['operations_per_sec']);
        $this->assertGreaterThan(0, $bench['read']['operations_per_sec']);
        $this->assertGreaterThanOrEqual(0, $bench['lock_latency_ms']);
    }

    public function testGlobalSentinelHelper(): void
    {
        $helper = sentinel();
        $this->assertInstanceOf(PerformanceSentinelInterface::class, $helper);
        $this->assertInstanceOf(QueryProfiler::class, $helper->getQueryProfiler());
        $this->assertInstanceOf(MemorySentinel::class, $helper->getMemorySentinel());
    }
}
