<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Notification\Controllers\NotificationController;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use Core\Http\Request;
use Core\Http\Session;
use Core\Inertia\Inertia;
use Core\Notification\NotificationService;
use PHPUnit\Framework\TestCase;

class NotificationControllerTest extends TestCase
{
    private Connection $db;
    private Session $session;
    private TenantContext $tenantContext;
    private AuthService $auth;
    private NotificationService $notificationService;
    private Inertia $inertia;
    private NotificationController $controller;
    private int $tenantId;
    private int $userId;

    protected function setUp(): void
    {
        $this->db = new Connection('sqlite::memory:');
        $migrator = new Migrator($this->db);
        $migrator->runMigrationsFrom(dirname(__DIR__, 2) . '/database/migrations');

        $_SESSION = [];
        $this->session = new Session();
        $this->session->startArrayDriver();

        $this->tenantContext = new TenantContext();
        $this->auth = new AuthService($this->db, $this->session, $this->tenantContext);
        $this->notificationService = new NotificationService($this->db);
        $this->inertia = Inertia::getInstance();

        $this->controller = new NotificationController(
            $this->auth,
            $this->notificationService,
            $this->inertia,
            $this->session
        );

        // Seed tenant & user
        $this->tenantId = (int) $this->db->insert('tenants', [
            'name' => 'Demo Org',
            'slug' => 'demo-org',
            'plan' => 'pro',
        ]);

        $this->userId = (int) $this->db->insert('users', [
            'name' => 'Jane Doe',
            'email' => 'jane@demo.com',
            'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
            'is_superadmin' => 0,
        ]);

        $this->db->insert('tenant_user', [
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'role' => 'owner',
        ]);
    }

    public function test_index_redirects_when_not_authenticated(): void
    {
        $request = new Request('GET', '/notifications');
        $response = $this->controller->index($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeader('Location'));
    }

    public function test_index_renders_notifications_page_with_data(): void
    {
        // Authenticate
        $this->auth->attempt('jane@demo.com', 'secret');

        // Create 2 notifications
        $this->notificationService->send($this->tenantId, $this->userId, 'Title 1', 'Message 1');
        $this->notificationService->send($this->tenantId, $this->userId, 'Title 2', 'Message 2');

        $request = new Request('GET', '/notifications', ['x-inertia' => 'true']);
        $this->inertia->setRequest($request);

        $response = $this->controller->index($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('Notifications/Index', $body['component']);
        $this->assertCount(2, $body['props']['notifications']);
        $this->assertSame(2, $body['props']['unreadCount']);
        $this->assertSame('all', $body['props']['activeFilter']);
    }

    public function test_mark_as_read_updates_status(): void
    {
        $this->auth->attempt('jane@demo.com', 'secret');
        $notifId = $this->notificationService->send($this->tenantId, $this->userId, 'Title', 'Message');

        $request = new Request('POST', "/notifications/{$notifId}/read");
        $response = $this->controller->markAsRead($request, (string) $notifId);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(0, $this->notificationService->getUnreadCount($this->tenantId, $this->userId));
    }

    public function test_mark_as_read_json_response(): void
    {
        $this->auth->attempt('jane@demo.com', 'secret');
        $notifId = $this->notificationService->send($this->tenantId, $this->userId, 'Title', 'Message');

        $request = new Request('POST', "/notifications/{$notifId}/read", ['accept' => 'application/json']);
        $response = $this->controller->markAsRead($request, (string) $notifId);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertTrue($data['success']);
        $this->assertSame(0, $data['unread_count']);
    }

    public function test_mark_all_as_read_clears_all_unread(): void
    {
        $this->auth->attempt('jane@demo.com', 'secret');
        $this->notificationService->send($this->tenantId, $this->userId, 'T1', 'M1');
        $this->notificationService->send($this->tenantId, $this->userId, 'T2', 'M2');

        $request = new Request('POST', '/notifications/read-all');
        $response = $this->controller->markAllAsRead($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(0, $this->notificationService->getUnreadCount($this->tenantId, $this->userId));
        $this->assertSame('Seluruh notifikasi telah ditandai sebagai dibaca.', $this->session->getFlash('success'));
    }

    public function test_delete_notification_removes_record(): void
    {
        $this->auth->attempt('jane@demo.com', 'secret');
        $notifId = $this->notificationService->send($this->tenantId, $this->userId, 'T1', 'M1');

        $request = new Request('POST', "/notifications/{$notifId}/delete");
        $response = $this->controller->delete($request, (string) $notifId);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(0, $this->notificationService->getUnreadCount($this->tenantId, $this->userId));
        $this->assertEmpty($this->notificationService->getForUser($this->tenantId, $this->userId));
        $this->assertSame('Notifikasi berhasil dihapus.', $this->session->getFlash('success'));
    }
}
