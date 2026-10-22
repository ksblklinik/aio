<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Container;
use Core\Database\Connection;
use Core\Events\Event;
use Core\Events\EventDispatcher;
use Core\Events\ListenerInterface;
use Core\Events\QueuedEventJob;
use Core\Queue\QueueManager;
use Core\Webhook\WebhookDispatcher;
use PDO;
use PHPUnit\Framework\TestCase;

// Dummy Domain Event for Testing
class TestOrderPlacedEvent extends Event
{
    public function __construct(
        int $tenantId,
        public string $orderNumber,
        public float $amount
    ) {
        parent::__construct(tenantId: $tenantId, userId: 42);
    }

    public function getEventName(): string
    {
        return 'commerce.order.placed';
    }
}

// Dummy Sync Listener
class SyncInvoiceListener implements ListenerInterface
{
    public static bool $handled = false;
    public static ?object $receivedEvent = null;

    public function handle(object $event): void
    {
        self::$handled = true;
        self::$receivedEvent = $event;
    }

    public function shouldQueue(): bool
    {
        return false;
    }
}

// Dummy Queued Listener
class AsyncNotificationListener implements ListenerInterface
{
    public static bool $executed = false;

    public function handle(object $event): void
    {
        self::$executed = true;
    }

    public function shouldQueue(): bool
    {
        return true;
    }
}

class EventDispatcherTest extends TestCase
{
    private Connection $db;
    private Container $container;
    private QueueManager $queue;
    private WebhookDispatcher $webhook;
    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        SyncInvoiceListener::$handled = false;
        SyncInvoiceListener::$receivedEvent = null;
        AsyncNotificationListener::$executed = false;

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Setup queue & webhook tables
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER,
                queue TEXT DEFAULT 'default',
                payload TEXT NOT NULL,
                attempts INTEGER DEFAULT 0,
                reserved_at DATETIME,
                available_at DATETIME,
                created_at DATETIME
            );
            CREATE TABLE IF NOT EXISTS failed_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER,
                queue TEXT,
                payload TEXT NOT NULL,
                exception TEXT NOT NULL,
                failed_at DATETIME
            );
            CREATE TABLE IF NOT EXISTS webhooks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                url TEXT NOT NULL,
                secret TEXT NOT NULL,
                events TEXT DEFAULT '[\"*\"]',
                is_active INTEGER DEFAULT 1,
                created_at DATETIME
            );
            CREATE TABLE IF NOT EXISTS webhook_deliveries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                webhook_id INTEGER NOT NULL,
                tenant_id INTEGER NOT NULL,
                event TEXT NOT NULL,
                url TEXT NOT NULL,
                payload TEXT NOT NULL,
                response_code INTEGER,
                response_body TEXT,
                status TEXT NOT NULL,
                error TEXT,
                attempt INTEGER DEFAULT 1,
                created_at DATETIME
            );
        ");

        $this->db = new Connection('sqlite::memory:');
        // Inject PDO directly
        $ref = new \ReflectionClass($this->db);
        $prop = $ref->getProperty('pdo');
        $prop->setAccessible(true);
        $prop->setValue($this->db, $pdo);

        $this->container = new Container();
        $this->queue = new QueueManager($this->db);
        $this->webhook = new WebhookDispatcher($this->db, $this->queue);
        $this->dispatcher = new EventDispatcher($this->container, $this->queue, $this->webhook);

        $this->container->singleton(QueueManager::class, fn() => $this->queue);
        $this->container->singleton(WebhookDispatcher::class, fn() => $this->webhook);
        $this->container->singleton(EventDispatcher::class, fn() => $this->dispatcher);
        $this->container->singleton(SyncInvoiceListener::class, fn() => new SyncInvoiceListener());
        $this->container->singleton(AsyncNotificationListener::class, fn() => new AsyncNotificationListener());
    }

    public function testDispatchExecutesCallableSyncListener(): void
    {
        $called = false;
        $capturedEvent = null;

        $this->dispatcher->listen('commerce.order.placed', function ($e) use (&$called, &$capturedEvent) {
            $called = true;
            $capturedEvent = $e;
        });

        $event = new TestOrderPlacedEvent(1, 'ORD-1001', 250000.0);
        $this->dispatcher->dispatch($event);

        $this->assertTrue($called);
        $this->assertSame($event, $capturedEvent);
        $this->assertEquals('ORD-1001', $capturedEvent->orderNumber);
    }

    public function testDispatchExecutesClassSyncListener(): void
    {
        $this->dispatcher->listen(TestOrderPlacedEvent::class, SyncInvoiceListener::class);

        $event = new TestOrderPlacedEvent(1, 'ORD-2002', 500000.0);
        $this->dispatcher->dispatch($event);

        $this->assertTrue(SyncInvoiceListener::$handled);
        $this->assertSame($event, SyncInvoiceListener::$receivedEvent);
    }

    public function testDispatchPushesQueuedListenerToQueue(): void
    {
        $this->dispatcher->listen(TestOrderPlacedEvent::class, AsyncNotificationListener::class);

        $event = new TestOrderPlacedEvent(1, 'ORD-3003', 750000.0);
        $this->dispatcher->dispatch($event);

        // Async listener tidak langsung dieksekusi secara sinkron
        $this->assertFalse(AsyncNotificationListener::$executed);

        // Namun pekerjaan masuk ke dalam antrean 'events'
        $job = $this->queue->pop('events');
        $this->assertNotNull($job);
        $this->assertEquals(QueuedEventJob::class, $job->getJobClass());

        // Eksekusi job via QueuedEventJob runner
        $queuedJob = new QueuedEventJob();
        $queuedJob->handle($job->getData(), $this->container);

        $this->assertTrue(AsyncNotificationListener::$executed);
    }

    public function testDispatchAutoBridgesToOutboundWebhooks(): void
    {
        // Daftarkan webhook outbound yang mendengarkan event 'commerce.order.placed'
        $this->webhook->register(
            tenantId: 1,
            name: 'Order Webhook',
            url: 'https://example.com/webhook',
            events: ['commerce.order.placed']
        );

        $event = new TestOrderPlacedEvent(1, 'ORD-9999', 1200000.0);
        $this->dispatcher->dispatch($event);

        // Webhook dispatcher harus secara otomatis memasukkan job ke antrean 'webhooks'
        $webhookJob = $this->queue->pop('webhooks');
        $this->assertNotNull($webhookJob);
        $data = $webhookJob->getData();
        $this->assertEquals('commerce.order.placed', $data['event']);
        $this->assertEquals('ORD-9999', $data['payload']['data']['orderNumber']);
    }

    public function testEventHelperDispatchesCorrectly(): void
    {
        $invoked = false;
        $this->dispatcher->listen('commerce.order.placed', function () use (&$invoked) {
            $invoked = true;
        });

        // Set global container singleton
        $containerRef = new \ReflectionClass(Container::class);
        $instanceProp = $containerRef->getProperty('instance');
        $instanceProp->setAccessible(true);
        $instanceProp->setValue(null, $this->container);

        $event = new TestOrderPlacedEvent(1, 'ORD-GLOBAL', 1000.0);
        event($event);

        $this->assertTrue($invoked);
    }
}
