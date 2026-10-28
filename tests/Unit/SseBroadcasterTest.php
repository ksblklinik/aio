<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Broadcasting\SseBroadcaster;
use Core\Broadcasting\SseBroadcasterInterface;
use Core\Container;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use PHPUnit\Framework\TestCase;

class SseBroadcasterTest extends TestCase
{
    private Connection $db;
    private TenantContext $tenantContext;
    private SseBroadcaster $broadcaster;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new Connection('sqlite::memory:');
        $migrator = new Migrator($this->db);
        $migrator->runMigrationsFrom(__DIR__ . '/../../database/migrations');

        $this->tenantContext = TenantContext::getInstance();
        $this->tenantContext->clear();

        $container = Container::getInstance();
        $container->instance(Connection::class, $this->db);
        $container->instance(TenantContext::class, $this->tenantContext);

        $this->tenantContext->setTenantId('1');

        $this->broadcaster = new SseBroadcaster($this->db, $this->tenantContext);
        $container->instance(SseBroadcasterInterface::class, $this->broadcaster);
    }

    public function testPublishAndGetEvents(): void
    {
        $id1 = $this->broadcaster->publish('queue', 'ticket_called', [
            'ticket_number' => 'A-001',
            'room' => 'Poli Umum',
        ]);

        $this->assertGreaterThan(0, $id1);

        $id2 = $this->broadcaster->publish('queue', 'ticket_called', [
            'ticket_number' => 'A-002',
            'room' => 'Poli Gigi',
        ]);

        $this->assertGreaterThan($id1, $id2);

        $events = $this->broadcaster->getEvents('queue');
        $this->assertCount(2, $events);
        $this->assertEquals('ticket_called', $events[0]['event']);
        $this->assertEquals('A-001', $events[0]['payload']['ticket_number']);
        $this->assertEquals('A-002', $events[1]['payload']['ticket_number']);
    }

    public function testIncrementalEventsWithAfterId(): void
    {
        $id1 = $this->broadcaster->publish('notifications', 'new_alert', ['msg' => 'Pesan 1']);
        $id2 = $this->broadcaster->publish('notifications', 'new_alert', ['msg' => 'Pesan 2']);
        $id3 = $this->broadcaster->publish('notifications', 'new_alert', ['msg' => 'Pesan 3']);

        $incremental = $this->broadcaster->getEvents('notifications', afterId: $id1);
        $this->assertCount(2, $incremental);
        $this->assertEquals($id2, $incremental[0]['id']);
        $this->assertEquals($id3, $incremental[1]['id']);

        $emptyIncremental = $this->broadcaster->getEvents('notifications', afterId: $id3);
        $this->assertEmpty($emptyIncremental);
    }

    public function testTenantIsolationAndPublicEvents(): void
    {
        // Tenant 1 publishes event
        $this->tenantContext->setTenantId('1');
        $this->broadcaster->publish('queue', 'ticket_called', ['tenant' => '1']);

        // Tenant 2 publishes event
        $this->tenantContext->setTenantId('2');
        $this->broadcaster->publish('queue', 'ticket_called', ['tenant' => '2']);

        // System/Public event (tenant_id = null)
        $this->broadcaster->publishPublic('queue', 'system_broadcast', ['notice' => 'Maintenance at 22:00']);

        // Read as Tenant 1: should see Tenant 1 event and public event, but NOT Tenant 2 event
        $this->tenantContext->setTenantId('1');
        $tenant1Events = $this->broadcaster->getEvents('queue');
        $this->assertCount(2, $tenant1Events);

        $payloads = array_map(fn($e) => $e['payload'], $tenant1Events);
        $this->assertContains(['tenant' => '1'], $payloads);
        $this->assertContains(['notice' => 'Maintenance at 22:00'], $payloads);

        // Read as Tenant 2: should see Tenant 2 event and public event
        $this->tenantContext->setTenantId('2');
        $tenant2Events = $this->broadcaster->getEvents('queue');
        $this->assertCount(2, $tenant2Events);
        $payloads2 = array_map(fn($e) => $e['payload'], $tenant2Events);
        $this->assertContains(['tenant' => '2'], $payloads2);
        $this->assertContains(['notice' => 'Maintenance at 22:00'], $payloads2);
    }

    public function testGetLatestEvent(): void
    {
        $this->broadcaster->publish('seal', 'seal_issued', ['doc' => 'DOC-001']);
        $this->broadcaster->publish('seal', 'seal_verified', ['doc' => 'DOC-001', 'status' => 'valid']);

        $latest = $this->broadcaster->getLatestEvent('seal');
        $this->assertNotNull($latest);
        $this->assertEquals('seal_verified', $latest['event']);
        $this->assertEquals('valid', $latest['payload']['status']);
    }

    public function testPruneOldEvents(): void
    {
        // Insert an event with an old timestamp (2 hours ago)
        $twoHoursAgo = date('c', time() - 7200);
        $this->db->statement(
            "INSERT INTO sse_events (tenant_id, channel, event, payload, created_at) VALUES (?, ?, ?, ?, ?)",
            ['1', 'temp', 'old_event', '{}', $twoHoursAgo]
        );

        // Insert a fresh event
        $this->broadcaster->publish('temp', 'fresh_event', ['data' => 123]);

        $this->assertCount(2, $this->broadcaster->getEvents('temp'));

        // Prune older than 60 minutes
        $prunedCount = $this->broadcaster->prune(60);
        $this->assertEquals(1, $prunedCount);

        $remaining = $this->broadcaster->getEvents('temp');
        $this->assertCount(1, $remaining);
        $this->assertEquals('fresh_event', $remaining[0]['event']);
    }

    public function testGlobalHelperSse(): void
    {
        $id = sse('system', 'ping', ['ts' => 12345678]);
        $this->assertGreaterThan(0, $id);

        $helperInstance = sse();
        $this->assertInstanceOf(SseBroadcasterInterface::class, $helperInstance);

        $latest = $helperInstance->getLatestEvent('system');
        $this->assertNotNull($latest);
        $this->assertEquals('ping', $latest['event']);
    }
}
