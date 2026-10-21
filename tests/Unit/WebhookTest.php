<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Container;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Queue\QueueManager;
use Core\Webhook\Jobs\SendWebhookJob;
use Core\Webhook\WebhookDispatcher;
use Exception;
use PHPUnit\Framework\TestCase;

class WebhookTest extends TestCase
{
    private Connection $db;
    private QueueManager $queue;
    private WebhookDispatcher $dispatcher;
    private Container $container;
    private int $tenantId;

    protected function setUp(): void
    {
        $this->db = new Connection('sqlite::memory:');
        $migrator = new Migrator($this->db);
        $migrator->runMigrationsFrom(dirname(__DIR__, 2) . '/database/migrations');

        $this->queue = new QueueManager($this->db);
        $this->dispatcher = new WebhookDispatcher($this->db, $this->queue);

        $this->container = new Container();
        $this->container->singleton(Connection::class, fn() => $this->db);
        $this->container->singleton(QueueManager::class, fn() => $this->queue);
        $this->container->singleton(WebhookDispatcher::class, fn() => $this->dispatcher);

        $this->tenantId = (int) $this->db->insert('tenants', [
            'name' => 'Acme Webhooks',
            'slug' => 'acme-wh',
            'plan' => 'enterprise',
        ]);

        SendWebhookJob::$httpSender = null;
    }

    protected function tearDown(): void
    {
        SendWebhookJob::$httpSender = null;
    }

    public function test_register_webhook_creates_record_with_secret(): void
    {
        $wh = $this->dispatcher->register(
            $this->tenantId,
            'Zapier Integration',
            'https://hooks.zapier.com/test',
            ['project.created', 'project.updated']
        );

        $this->assertGreaterThan(0, $wh['id']);
        $this->assertStringStartsWith('whsec_', $wh['secret']);
        $this->assertSame('Zapier Integration', $wh['name']);
        $this->assertSame(['project.created', 'project.updated'], $wh['events']);

        $record = $this->dispatcher->find($this->tenantId, $wh['id']);
        $this->assertNotNull($record);
        $this->assertSame(1, (int) $record['is_active']);
    }

    public function test_toggle_and_delete_webhook(): void
    {
        $wh = $this->dispatcher->register($this->tenantId, 'To Toggle', 'https://example.com');
        $id = $wh['id'];

        $this->assertTrue($this->dispatcher->toggle($this->tenantId, $id));
        $record = $this->dispatcher->find($this->tenantId, $id);
        $this->assertSame(0, (int) $record['is_active']);

        $this->assertTrue($this->dispatcher->toggle($this->tenantId, $id));
        $record = $this->dispatcher->find($this->tenantId, $id);
        $this->assertSame(1, (int) $record['is_active']);

        $this->assertTrue($this->dispatcher->delete($this->tenantId, $id));
        $this->assertNull($this->dispatcher->find($this->tenantId, $id));
    }

    public function test_dispatch_enqueues_job_for_matching_events(): void
    {
        // Webhook 1: wildcard *
        $this->dispatcher->register($this->tenantId, 'Catch All', 'https://hook1.com', ['*']);
        // Webhook 2: project.created
        $this->dispatcher->register($this->tenantId, 'Project Only', 'https://hook2.com', ['project.created']);
        // Webhook 3: auth.login (should not be triggered for project.created)
        $this->dispatcher->register($this->tenantId, 'Auth Only', 'https://hook3.com', ['auth.login']);

        $count = $this->dispatcher->dispatch($this->tenantId, 'project.created', ['project_id' => 42, 'name' => 'Alpha']);

        $this->assertSame(2, $count);
        $this->assertSame(2, $this->queue->size('webhooks'));

        // Pop first job
        $job1 = $this->queue->pop('webhooks');
        $this->assertNotNull($job1);
        $this->assertSame(SendWebhookJob::class, $job1->getJobClass());
        $this->assertSame('project.created', $job1->getData()['event']);
        $this->assertSame('https://hook1.com', $job1->getData()['url']);

        // Pop second job
        $job2 = $this->queue->pop('webhooks');
        $this->assertNotNull($job2);
        $this->assertSame('https://hook2.com', $job2->getData()['url']);

        // No third job
        $job3 = $this->queue->pop('webhooks');
        $this->assertNull($job3);
    }

    public function test_calculate_signature_matches_hmac_sha256(): void
    {
        $payload = '{"id":"123","event":"test"}';
        $timestamp = 1700000000;
        $secret = 'test_secret_key';

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
        $actual = $this->dispatcher->calculateSignature($payload, $timestamp, $secret);

        $this->assertSame($expected, $actual);
    }

    public function test_send_webhook_job_execution_with_mocked_http_sender(): void
    {
        $wh = $this->dispatcher->register($this->tenantId, 'Webhook Target', 'https://api.external.com/receiver');
        $this->dispatcher->dispatch($this->tenantId, 'project.created', ['title' => 'Project X']);

        $job = $this->queue->pop('webhooks');
        $this->assertNotNull($job);

        // Mock HTTP sender
        $capturedHeaders = [];
        SendWebhookJob::$httpSender = function (string $url, array $headers, string $body) use (&$capturedHeaders) {
            $capturedHeaders = $headers;
            return [
                'status' => 200,
                'body' => json_encode(['received' => true]),
            ];
        };

        $jobInstance = new SendWebhookJob();
        $jobInstance->handle($job->getData(), $this->container);

        // Verify headers were signed
        $this->assertSame('application/json', $capturedHeaders['Content-Type']);
        $this->assertSame('project.created', $capturedHeaders['X-Aio-Event']);
        $this->assertArrayHasKey('X-Aio-Timestamp', $capturedHeaders);
        $this->assertStringStartsWith('sha256=', $capturedHeaders['X-Aio-Signature']);

        // Verify delivery record in database
        $deliveries = $this->dispatcher->getDeliveries($this->tenantId, $wh['id']);
        $this->assertCount(1, $deliveries);
        $this->assertSame('success', $deliveries[0]['status']);
        $this->assertSame(200, (int) $deliveries[0]['response_code']);
        $this->assertSame('project.created', $deliveries[0]['event']);
        $this->assertGreaterThanOrEqual(0, $deliveries[0]['duration_ms']);
    }

    public function test_send_webhook_job_records_failure_on_error(): void
    {
        $wh = $this->dispatcher->register($this->tenantId, 'Failing Webhook', 'https://failing.com/hook');
        $this->dispatcher->dispatch($this->tenantId, 'task.failed', ['error' => 'Disk full']);

        $job = $this->queue->pop('webhooks');
        $this->assertNotNull($job);

        SendWebhookJob::$httpSender = function (string $url, array $headers, string $body) {
            return [
                'status' => 500,
                'body' => 'Internal Server Error',
            ];
        };

        $jobInstance = new SendWebhookJob();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Webhook delivery failed with HTTP status 500");

        try {
            $jobInstance->handle($job->getData(), $this->container);
        } finally {
            $deliveries = $this->dispatcher->getDeliveries($this->tenantId, $wh['id']);
            $this->assertCount(1, $deliveries);
            $this->assertSame('failed', $deliveries[0]['status']);
            $this->assertSame(500, (int) $deliveries[0]['response_code']);
        }
    }
}
