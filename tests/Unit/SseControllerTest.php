<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Broadcasting\SseBroadcaster;
use Core\Broadcasting\SseController;
use Core\Container;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use Core\Http\Request;
use PHPUnit\Framework\TestCase;

class SseControllerTest extends TestCase
{
    private Connection $db;
    private TenantContext $tenantContext;
    private SseBroadcaster $broadcaster;
    private SseController $controller;

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
        $this->controller = new SseController($this->broadcaster, $this->tenantContext);
    }

    public function testPollEndpointReturnsJsonEvents(): void
    {
        $this->broadcaster->publish('queue', 'ticket_called', ['ticket' => 'A-001']);
        $this->broadcaster->publish('queue', 'ticket_called', ['ticket' => 'A-002']);

        $request = new Request(
            method: 'GET',
            uri: '/api/sse/poll',
            queryParams: ['channel' => 'queue']
        );

        $response = $this->controller->poll($request);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeader('Content-Type') ?? '');

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('success', $data['status']);
        $this->assertEquals('queue', $data['channel']);
        $this->assertCount(2, $data['events']);
        $this->assertEquals('ticket_called', $data['events'][0]['event']);
        $this->assertEquals('A-001', $data['events'][0]['payload']['ticket']);
        $this->assertEquals(2, $data['count']);
    }

    public function testPollEndpointWithAfterId(): void
    {
        $id1 = $this->broadcaster->publish('notifications', 'alert', ['m' => 1]);
        $id2 = $this->broadcaster->publish('notifications', 'alert', ['m' => 2]);

        $request = new Request(
            method: 'GET',
            uri: '/api/sse/poll',
            queryParams: ['channel' => 'notifications', 'after_id' => (string)$id1]
        );

        $response = $this->controller->poll($request);
        $data = json_decode($response->getContent(), true);

        $this->assertCount(1, $data['events']);
        $this->assertEquals($id2, $data['events'][0]['id']);
        $this->assertEquals($id2, $data['last_id']);
    }

    public function testStreamResponseHeadersAndFormatting(): void
    {
        $this->broadcaster->publish('queue', 'ticket_called', ['ticket_number' => 'B-005']);

        $request = new Request(
            method: 'GET',
            uri: '/api/sse/stream',
            queryParams: ['channel' => 'queue']
        );

        $response = $this->controller->stream($request);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('text/event-stream', $response->getHeader('Content-Type') ?? '');
        $this->assertStringContainsString('no-cache', $response->getHeader('Cache-Control') ?? '');

        // Capture buffer output of SSE streaming runner
        ob_start();
        $response->send();
        $output = ob_get_clean();

        $this->assertStringContainsString(': ping', $output);
        $this->assertStringContainsString('event: ticket_called', $output);
        $this->assertStringContainsString('"ticket_number":"B-005"', $output);
    }
}
