<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Notification\NotificationService;
use PHPUnit\Framework\TestCase;

class NotificationTest extends TestCase
{
    private Connection $db;
    private NotificationService $notificationService;
    private int $tenantA;
    private int $tenantB;
    private int $user1;
    private int $user2;
    private int $user3;

    protected function setUp(): void
    {
        $this->db = new Connection('sqlite::memory:');
        $migrator = new Migrator($this->db);
        $migrator->runMigrationsFrom(dirname(__DIR__, 2) . '/database/migrations');

        $this->notificationService = new NotificationService($this->db);

        // Seed tenants
        $this->tenantA = (int) $this->db->insert('tenants', [
            'name' => 'Acme Corp',
            'slug' => 'acme-corp',
            'plan' => 'pro',
        ]);

        $this->tenantB = (int) $this->db->insert('tenants', [
            'name' => 'Beta Industries',
            'slug' => 'beta-ind',
            'plan' => 'starter',
        ]);

        // Seed users
        $this->user1 = (int) $this->db->insert('users', [
            'name' => 'Alice Admin',
            'email' => 'alice@acme.com',
            'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
        ]);

        $this->user2 = (int) $this->db->insert('users', [
            'name' => 'Bob Member',
            'email' => 'bob@acme.com',
            'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
        ]);

        $this->user3 = (int) $this->db->insert('users', [
            'name' => 'Charlie Beta',
            'email' => 'charlie@beta.com',
            'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
        ]);

        // Tenant A has Alice and Bob
        $this->db->insert('tenant_user', [
            'tenant_id' => $this->tenantA,
            'user_id' => $this->user1,
            'role' => 'owner',
        ]);
        $this->db->insert('tenant_user', [
            'tenant_id' => $this->tenantA,
            'user_id' => $this->user2,
            'role' => 'member',
        ]);

        // Tenant B has Charlie
        $this->db->insert('tenant_user', [
            'tenant_id' => $this->tenantB,
            'user_id' => $this->user3,
            'role' => 'owner',
        ]);
    }

    public function test_send_notification_creates_record(): void
    {
        $notifId = $this->notificationService->send(
            $this->tenantA,
            $this->user1,
            'Proyek Baru Dibuat',
            'Proyek Alpha Website telah berhasil dibuat.',
            'success',
            '/projects/1',
            'folder'
        );

        $this->assertGreaterThan(0, $notifId);

        $record = $this->db->selectOne("SELECT * FROM notifications WHERE id = ?", [$notifId]);
        $this->assertNotNull($record);
        $this->assertSame($this->tenantA, (int) $record['tenant_id']);
        $this->assertSame($this->user1, (int) $record['user_id']);
        $this->assertSame('success', $record['type']);
        $this->assertSame('Proyek Baru Dibuat', $record['title']);
        $this->assertSame('Proyek Alpha Website telah berhasil dibuat.', $record['message']);
        $this->assertSame('/projects/1', $record['action_url']);
        $this->assertSame('folder', $record['icon']);
        $this->assertNull($record['read_at']);
    }

    public function test_send_falls_back_to_info_on_invalid_type(): void
    {
        $notifId = $this->notificationService->send(
            $this->tenantA,
            $this->user1,
            'Pemberitahuan',
            'Pesan acak',
            'unknown_type'
        );

        $record = $this->db->selectOne("SELECT type FROM notifications WHERE id = ?", [$notifId]);
        $this->assertSame('info', $record['type']);
    }

    public function test_get_for_user_and_unread_count(): void
    {
        $this->notificationService->send($this->tenantA, $this->user1, 'Notif 1', 'Pesan 1');
        $this->notificationService->send($this->tenantA, $this->user1, 'Notif 2', 'Pesan 2');
        $this->notificationService->send($this->tenantA, $this->user1, 'Notif 3', 'Pesan 3');

        $this->assertSame(3, $this->notificationService->getUnreadCount($this->tenantA, $this->user1));

        $allNotifs = $this->notificationService->getForUser($this->tenantA, $this->user1);
        $this->assertCount(3, $allNotifs);
        $this->assertFalse($allNotifs[0]['is_read']);
    }

    public function test_mark_as_read_updates_single_notification(): void
    {
        $id1 = $this->notificationService->send($this->tenantA, $this->user1, 'Notif 1', 'Pesan 1');
        $id2 = $this->notificationService->send($this->tenantA, $this->user1, 'Notif 2', 'Pesan 2');

        $this->assertTrue($this->notificationService->markAsRead($this->tenantA, $this->user1, $id1));
        // Calling it again on already read returns false
        $this->assertFalse($this->notificationService->markAsRead($this->tenantA, $this->user1, $id1));

        $this->assertSame(1, $this->notificationService->getUnreadCount($this->tenantA, $this->user1));

        $unreadOnly = $this->notificationService->getForUser($this->tenantA, $this->user1, 20, true);
        $this->assertCount(1, $unreadOnly);
        $this->assertSame($id2, $unreadOnly[0]['id']);
    }

    public function test_mark_all_as_read(): void
    {
        $this->notificationService->send($this->tenantA, $this->user1, 'Notif 1', 'Pesan 1');
        $this->notificationService->send($this->tenantA, $this->user1, 'Notif 2', 'Pesan 2');

        $updatedCount = $this->notificationService->markAllAsRead($this->tenantA, $this->user1);
        $this->assertSame(2, $updatedCount);
        $this->assertSame(0, $this->notificationService->getUnreadCount($this->tenantA, $this->user1));
    }

    public function test_broadcast_to_tenant_reaches_members_and_can_exclude_sender(): void
    {
        // Broadcast to Tenant A excluding Alice (user1)
        $count = $this->notificationService->broadcastToTenant(
            $this->tenantA,
            'Sistem Diperbarui',
            'Modul CRM telah diaktifkan.',
            'info',
            '/modules',
            'bell',
            $this->user1
        );

        $this->assertSame(1, $count); // Only Bob (user2) received it

        $this->assertSame(0, $this->notificationService->getUnreadCount($this->tenantA, $this->user1));
        $this->assertSame(1, $this->notificationService->getUnreadCount($this->tenantA, $this->user2));

        // Now broadcast to all without excluding
        $countAll = $this->notificationService->broadcastToTenant(
            $this->tenantA,
            'Pengumuman Libur',
            'Kantor libur besok.',
            'warning'
        );

        $this->assertSame(2, $countAll); // Both Alice and Bob
        $this->assertSame(1, $this->notificationService->getUnreadCount($this->tenantA, $this->user1));
        $this->assertSame(2, $this->notificationService->getUnreadCount($this->tenantA, $this->user2));
    }

    public function test_delete_notification(): void
    {
        $id = $this->notificationService->send($this->tenantA, $this->user1, 'To Delete', 'Msg');
        $this->assertTrue($this->notificationService->delete($this->tenantA, $this->user1, $id));

        $this->assertSame(0, $this->notificationService->getUnreadCount($this->tenantA, $this->user1));
        $this->assertEmpty($this->notificationService->getForUser($this->tenantA, $this->user1));
    }

    public function test_tenant_and_user_isolation(): void
    {
        $idA = $this->notificationService->send($this->tenantA, $this->user1, 'Tenant A Notif', 'Secret A');

        // Charlie in Tenant B cannot see Alice's notifications
        $this->assertSame(0, $this->notificationService->getUnreadCount($this->tenantB, $this->user3));
        $this->assertEmpty($this->notificationService->getForUser($this->tenantB, $this->user3));

        // Charlie cannot mark as read Alice's notification
        $this->assertFalse($this->notificationService->markAsRead($this->tenantB, $this->user3, $idA));

        // Bob in Tenant A cannot mark as read Alice's notification
        $this->assertFalse($this->notificationService->markAsRead($this->tenantA, $this->user2, $idA));

        // Alice's notification is still unread
        $this->assertSame(1, $this->notificationService->getUnreadCount($this->tenantA, $this->user1));
    }
}
