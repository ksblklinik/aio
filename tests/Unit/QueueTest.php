<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Queue\Job;
use Core\Queue\QueueManager;
use Exception;
use PHPUnit\Framework\TestCase;

class QueueTest extends TestCase
{
    private Connection $db;
    private QueueManager $queue;

    protected function setUp(): void
    {
        $this->db = new Connection('sqlite::memory:');
        $migrator = new Migrator($this->db);
        $migrator->runMigrationsFrom(dirname(__DIR__, 2) . '/database/migrations');

        $this->queue = new QueueManager($this->db);
    }

    public function test_push_adds_job_to_queue(): void
    {
        $jobId = $this->queue->push(
            'App\Jobs\SampleJob',
            ['foo' => 'bar'],
            tenantId: 1,
            queue: 'default',
            delaySeconds: 0,
            maxTries: 3
        );

        $this->assertGreaterThan(0, $jobId);
        $this->assertSame(1, $this->queue->size('default'));

        $record = $this->db->selectOne("SELECT * FROM jobs WHERE id = ?", [$jobId]);
        $this->assertNotNull($record);
        $this->assertSame(1, (int) $record['tenant_id']);
        $this->assertSame('default', $record['queue']);
        $this->assertSame(0, (int) $record['attempts']);
        $this->assertNull($record['reserved_at']);
    }

    public function test_pop_retrieves_and_reserves_job(): void
    {
        $this->queue->push('App\Jobs\SampleJob', ['id' => 123]);

        $job = $this->queue->pop('default');

        $this->assertInstanceOf(Job::class, $job);
        $this->assertSame('App\Jobs\SampleJob', $job->getJobClass());
        $this->assertSame(['id' => 123], $job->getData());
        $this->assertSame(1, $job->getAttempts());
        $this->assertSame(3, $job->getMaxTries());

        // Second pop immediately returns null because it is already reserved
        $secondPop = $this->queue->pop('default');
        $this->assertNull($secondPop);
    }

    public function test_delayed_job_is_not_popped_until_available(): void
    {
        $this->queue->push('App\Jobs\DelayedJob', [], delaySeconds: 100);

        $job = $this->queue->pop('default');
        $this->assertNull($job);
    }

    public function test_delete_removes_job(): void
    {
        $this->queue->push('App\Jobs\ToComplete', []);
        $job = $this->queue->pop('default');
        $this->assertNotNull($job);

        $this->queue->delete($job);

        $this->assertSame(0, $this->queue->size('default'));
        $this->assertNull($this->db->selectOne("SELECT * FROM jobs WHERE id = ?", [$job->getId()]));
    }

    public function test_release_resets_reservation_with_delay(): void
    {
        $this->queue->push('App\Jobs\RetryableJob', []);
        $job = $this->queue->pop('default');
        $this->assertNotNull($job);

        // Release with 0 delay so it's immediately available again
        $this->queue->release($job, 0);

        $reloaded = $this->db->selectOne("SELECT * FROM jobs WHERE id = ?", [$job->getId()]);
        $this->assertNull($reloaded['reserved_at']);

        // Can pop again
        $secondPop = $this->queue->pop('default');
        $this->assertNotNull($secondPop);
        $this->assertSame(2, $secondPop->getAttempts());
    }

    public function test_fail_moves_job_to_failed_jobs_table(): void
    {
        $this->queue->push('App\Jobs\CrashingJob', ['test' => 1], tenantId: 5);
        $job = $this->queue->pop('default');
        $this->assertNotNull($job);

        $exception = new Exception("Connection timeout error");
        $failedId = $this->queue->fail($job, $exception);

        $this->assertGreaterThan(0, $failedId);
        $this->assertSame(0, $this->queue->size('default'));
        $this->assertSame(1, $this->queue->failedCount());

        $failedRecord = $this->db->selectOne("SELECT * FROM failed_jobs WHERE id = ?", [$failedId]);
        $this->assertNotNull($failedRecord);
        $this->assertSame(5, (int) $failedRecord['tenant_id']);
        $this->assertSame('default', $failedRecord['queue']);
        $this->assertStringContainsString("Connection timeout error", $failedRecord['exception']);
    }

    public function test_retry_moves_failed_job_back_to_queue(): void
    {
        $this->queue->push('App\Jobs\FixableJob', ['param' => 42]);
        $job = $this->queue->pop('default');
        $this->assertNotNull($job);

        $failedId = $this->queue->fail($job, new Exception("Temporary outage"));
        $this->assertSame(1, $this->queue->failedCount());

        $retried = $this->queue->retry($failedId);
        $this->assertTrue($retried);

        $this->assertSame(0, $this->queue->failedCount());
        $this->assertSame(1, $this->queue->size('default'));

        $popped = $this->queue->pop('default');
        $this->assertNotNull($popped);
        $this->assertSame('App\Jobs\FixableJob', $popped->getJobClass());
        $this->assertSame(1, $popped->getAttempts());
    }
}
