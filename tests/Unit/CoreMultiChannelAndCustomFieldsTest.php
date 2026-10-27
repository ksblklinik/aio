<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\CustomField\FieldManager;
use Core\Database\Connection;
use Core\Database\TenantContext;
use Core\Foundation\Application;
use Core\Mail\MailerDriverInterface;
use Core\Mail\Message;
use Core\Notification\ChannelManager;
use Core\Notification\Channels\DatabaseChannel;
use Core\Notification\Channels\EmailChannel;
use Core\Notification\Channels\TelegramChannel;
use Core\Notification\Channels\WhatsAppChannel;
use Core\Notification\NotificationService;
use PHPUnit\Framework\TestCase;

class CoreMultiChannelAndCustomFieldsTest extends TestCase
{
    private Connection $db;
    private TenantContext $tenantContext;
    private NotificationService $notifService;
    private ChannelManager $channelManager;
    private FieldManager $fieldManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new Connection('sqlite::memory:');

        // Setup notifications table
        $this->db->getPdo()->exec("
            CREATE TABLE IF NOT EXISTS notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                type VARCHAR(20) NOT NULL DEFAULT 'info',
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                action_url VARCHAR(255) NULL,
                icon VARCHAR(50) NULL,
                read_at DATETIME NULL,
                created_at DATETIME NOT NULL
            );

            CREATE TABLE IF NOT EXISTS custom_field_definitions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                entity_type VARCHAR(64) NOT NULL,
                field_name VARCHAR(64) NOT NULL,
                label VARCHAR(255) NOT NULL,
                type VARCHAR(32) NOT NULL DEFAULT 'text',
                options TEXT NULL,
                is_required INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(tenant_id, entity_type, field_name)
            );

            CREATE TABLE IF NOT EXISTS custom_field_values (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                entity_type VARCHAR(64) NOT NULL,
                entity_id VARCHAR(64) NOT NULL,
                field_id INTEGER NOT NULL,
                value TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(tenant_id, entity_type, entity_id, field_id)
            );
        ");

        $this->tenantContext = TenantContext::getInstance();
        $this->tenantContext->setTenantId('1', ['name' => 'Yayasan Nusantara']);

        $this->notifService = new NotificationService($this->db);
        $this->channelManager = new ChannelManager($this->tenantContext);
        $this->fieldManager = new FieldManager($this->db, $this->tenantContext);
    }

    public function test_multi_channel_notification_dispatch_and_mocking(): void
    {
        // 1. Setup channels
        $dbChannel = new DatabaseChannel($this->notifService);

        $sentMails = [];
        $mockMailer = $this->createMock(MailerDriverInterface::class);
        $mockMailer->method('send')->willReturnCallback(function (Message $m) use (&$sentMails) {
            $sentMails[] = $m;
            return true;
        });
        $emailChannel = new EmailChannel($mockMailer);

        $sentWa = [];
        $waChannel = new WhatsAppChannel('https://wa.mock/api', 'secret-token', function ($payload) use (&$sentWa) {
            $sentWa[] = $payload;
            return true;
        });

        $sentTg = [];
        $tgChannel = new TelegramChannel('mock-bot-token', function ($payload) use (&$sentTg) {
            $sentTg[] = $payload;
            return true;
        });

        $this->channelManager->registerChannel('database', $dbChannel);
        $this->channelManager->registerChannel('email', $emailChannel);
        $this->channelManager->registerChannel('whatsapp', $waChannel);
        $this->channelManager->registerChannel('telegram', $tgChannel);

        $recipient = (object) [
            'id' => 99,
            'name' => 'Ahmad Pengurus',
            'email' => 'ahmad@yayasan.org',
            'phone' => '08123456789',
            'telegram_chat_id' => '12345678',
        ];

        // Broadcast across all 4 channels
        $results = $this->channelManager->to($recipient, 1)
            ->via(['database', 'email', 'whatsapp', 'telegram'])
            ->actionUrl('https://yayasan.org/sk/001')
            ->send('Panggilan Rapat Pleno', 'Rapat diadakan besok pukul 09.00 WIB');

        $this->assertTrue($results['database']);
        $this->assertTrue($results['email']);
        $this->assertTrue($results['whatsapp']);
        $this->assertTrue($results['telegram']);

        // Assert database record
        $rows = $this->db->selectAll("SELECT * FROM notifications WHERE user_id = 99");
        $this->assertCount(1, $rows);
        $this->assertSame('Panggilan Rapat Pleno', $rows[0]['title']);

        // Assert email
        $this->assertCount(1, $sentMails);
        $this->assertSame('ahmad@yayasan.org', $sentMails[0]->getTo());

        // Assert WhatsApp phone formatting
        $this->assertCount(1, $sentWa);
        $this->assertSame('628123456789', $sentWa[0]['phone']);
        $this->assertStringContainsString('*Panggilan Rapat Pleno*', $sentWa[0]['message']);

        // Assert Telegram
        $this->assertCount(1, $sentTg);
        $this->assertSame('12345678', $sentTg[0]['chat_id']);
        $this->assertStringContainsString('<b>Panggilan Rapat Pleno</b>', $sentTg[0]['text']);
    }

    public function test_custom_fields_definition_and_upsert(): void
    {
        $def = $this->fieldManager->define('member', 'golongan_darah', 'Golongan Darah', 'select', ['A', 'B', 'AB', 'O'], true);

        $this->assertSame('member', $def['entity_type']);
        $this->assertSame('golongan_darah', $def['field_name']);
        $this->assertSame('select', $def['type']);
        $this->assertTrue($def['is_required']);

        // Retrieve fields
        $fields = $this->fieldManager->getFields('member');
        $this->assertCount(1, $fields);
        $this->assertSame('Golongan Darah', $fields[0]['label']);
        $this->assertSame(['A', 'B', 'AB', 'O'], $fields[0]['options']);

        // Upsert same field
        $updated = $this->fieldManager->define('member', 'golongan_darah', 'Gol. Darah Resminya', 'select', ['A', 'B', 'AB', 'O'], false);
        $this->assertFalse($updated['is_required']);
        $this->assertSame('Gol. Darah Resminya', $updated['label']);

        $fieldsAfter = $this->fieldManager->getFields('member');
        $this->assertCount(1, $fieldsAfter); // Masih 1, bukan duplicate
    }

    public function test_custom_fields_validation(): void
    {
        $this->fieldManager->define('donatur', 'nominal_komitmen', 'Nominal Komitmen', 'number', [], true);
        $this->fieldManager->define('donatur', 'frekuensi', 'Frekuensi', 'select', ['bulanan', 'tahunan'], true);
        $this->fieldManager->define('donatur', 'tanggal_mulai', 'Tanggal Mulai', 'date', [], false);

        // 1. Invalid input: missing required & invalid number
        $errors1 = $this->fieldManager->validate('donatur', [
            'nominal_komitmen' => 'bukan_angka',
            // missing frekuensi
        ]);

        $this->assertArrayHasKey('nominal_komitmen', $errors1);
        $this->assertArrayHasKey('frekuensi', $errors1);

        // 2. Invalid select option
        $errors2 = $this->fieldManager->validate('donatur', [
            'nominal_komitmen' => '100000',
            'frekuensi' => 'harian', // Not in ['bulanan', 'tahunan']
        ]);
        $this->assertArrayHasKey('frekuensi', $errors2);

        // 3. Valid input
        $errors3 = $this->fieldManager->validate('donatur', [
            'nominal_komitmen' => 500000,
            'frekuensi' => 'bulanan',
            'tanggal_mulai' => '2026-11-01',
        ]);
        $this->assertEmpty($errors3);
    }

    public function test_custom_fields_save_and_retrieve_values_with_tenant_isolation(): void
    {
        // Tenant 1 definitions
        $this->fieldManager->define('member', 'nama_wali', 'Nama Wali', 'text', [], false, 1);
        $this->fieldManager->define('member', 'poin_relawan', 'Poin Relawan', 'number', [], false, 1);

        // Tenant 2 definitions
        $this->fieldManager->define('member', 'nama_wali', 'Nama Wali', 'text', [], false, 2);

        // Save values for Tenant 1
        $this->fieldManager->saveValues('member', 'MEM-001', [
            'nama_wali' => 'Bapak Hartono',
            'poin_relawan' => 150,
        ], 1);

        // Save values for Tenant 2 on same entity ID
        $this->fieldManager->saveValues('member', 'MEM-001', [
            'nama_wali' => 'Ibu Kartini',
        ], 2);

        // Retrieve values for Tenant 1
        $valuesT1 = $this->fieldManager->getValues('member', 'MEM-001', 1);
        $this->assertSame('Bapak Hartono', $valuesT1['nama_wali']);
        $this->assertSame(150, $valuesT1['poin_relawan']);

        // Retrieve values for Tenant 2
        $valuesT2 = $this->fieldManager->getValues('member', 'MEM-001', 2);
        $this->assertSame('Ibu Kartini', $valuesT2['nama_wali']);
        $this->assertArrayNotHasKey('poin_relawan', $valuesT2); // Tenant 2 tidak punya field ini

        // Delete field
        $this->fieldManager->deleteField('member', 'nama_wali', 1);
        $valuesAfterDelete = $this->fieldManager->getValues('member', 'MEM-001', 1);
        $this->assertArrayNotHasKey('nama_wali', $valuesAfterDelete);
    }

    public function test_global_helpers_notify_and_custom_fields(): void
    {
        $app = new Application(dirname(__DIR__, 2));
        $app->getContainer()->singleton(Connection::class, fn() => $this->db);

        $this->assertInstanceOf(ChannelManager::class, notification());
        $this->assertInstanceOf(FieldManager::class, custom_fields());
    }
}
