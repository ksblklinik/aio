<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Container;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Mail\AccountLifecycleService;
use Core\Mail\LogMailerDriver;
use Core\Mail\Mailer;
use Core\Mail\Message;
use Core\Mail\SendMailJob;
use Core\Queue\QueueManager;
use PHPUnit\Framework\TestCase;

class MailerAndLifecycleTest extends TestCase
{
    private Connection $db;
    private Migrator $migrator;
    private string $tempLogPath;
    private LogMailerDriver $logDriver;
    private QueueManager $queue;
    private Mailer $mailer;
    private AccountLifecycleService $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new Connection('sqlite::memory:');
        $this->migrator = new Migrator($this->db);
        $this->migrator->runMigrationsFrom(__DIR__ . '/../../database/migrations');

        $this->tempLogPath = sys_get_temp_dir() . '/aio_test_mail_' . bin2hex(random_bytes(6)) . '.log';
        $this->logDriver = new LogMailerDriver($this->tempLogPath);
        $this->queue = new QueueManager($this->db);
        $this->mailer = new Mailer($this->logDriver, $this->queue);
        $this->lifecycle = new AccountLifecycleService($this->db, $this->mailer);

        $container = Container::getInstance();
        $container->instance(Connection::class, $this->db);
        $container->instance(QueueManager::class, $this->queue);
        $container->instance(Mailer::class, $this->mailer);
        $container->instance(AccountLifecycleService::class, $this->lifecycle);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempLogPath)) {
            @unlink($this->tempLogPath);
        }
        parent::tearDown();
    }

    public function test_message_dto_and_serialization(): void
    {
        $msg = (new Message())
            ->to('john@example.com')
            ->subject('Hello World')
            ->html('<h1>Welcome</h1><p>Test paragraph</p>')
            ->from('system@aio.local')
            ->attach('/tmp/invoice.pdf');

        $this->assertSame('john@example.com', $msg->getTo());
        $this->assertSame('Hello World', $msg->getSubject());
        $this->assertSame('WelcomeTest paragraph', $msg->getText());
        $this->assertCount(1, $msg->getAttachments());

        $serialized = $msg->toArray();
        $reconstructed = Message::fromArray($serialized);

        $this->assertSame($msg->getTo(), $reconstructed->getTo());
        $this->assertSame($msg->getSubject(), $reconstructed->getSubject());
        $this->assertSame($msg->getHtml(), $reconstructed->getHtml());
    }

    public function test_log_mailer_driver_writes_formatted_log(): void
    {
        $msg = (new Message())
            ->to('doctor@clinic.local')
            ->subject('Jadwal Pasien Hari Ini')
            ->html('<p>Ada 5 pasien menunggu pemeriksaan.</p>');

        $success = $this->logDriver->send($msg);
        $this->assertTrue($success);
        $this->assertFileExists($this->tempLogPath);

        $content = file_get_contents($this->tempLogPath);
        $this->assertStringContainsString('doctor@clinic.local', $content);
        $this->assertStringContainsString('Jadwal Pasien Hari Ini', $content);
        $this->assertStringContainsString('Ada 5 pasien menunggu pemeriksaan.', $content);
    }

    public function test_mailer_sync_and_async_queue_delivery(): void
    {
        // 1. Sync send
        $msg = (new Message())
            ->to('client@example.com')
            ->subject('Tagihan Faktur')
            ->text('Mohon bayar tagihan Anda.');

        $this->assertTrue($this->mailer->send($msg));

        // 2. Queue send
        $queueMsg = (new Message())
            ->to('queued@example.com')
            ->subject('Async Email Delivery')
            ->text('Ini dikirim via Queue.');

        $this->mailer->queue($queueMsg, 'emails');

        // Pop job from queue and process
        $job = $this->queue->pop('emails');
        $this->assertNotNull($job);
        $this->assertSame(SendMailJob::class, $job->getJobClass());

        $sendMailJob = new SendMailJob();
        $sendMailJob->handle($job->getData(), Container::getInstance());

        $logContent = file_get_contents($this->tempLogPath);
        $this->assertStringContainsString('queued@example.com', $logContent);
        $this->assertStringContainsString('Async Email Delivery', $logContent);
    }

    public function test_account_lifecycle_invitation_flow(): void
    {
        // Create sample tenant and user
        $tenantId = (int) $this->db->insert('tenants', [
            'name' => 'Klinik Sejahtera',
            'slug' => 'klinik-sejahtera',
            'plan' => 'pro',
        ]);

        $userId = (int) $this->db->insert('users', [
            'name' => 'Budi Dokter',
            'email' => 'budi@klinik.local',
            'password_hash' => password_hash('password123', PASSWORD_DEFAULT),
        ]);

        // Invite member
        $token = $this->lifecycle->inviteMember($tenantId, 'budi@klinik.local', 'admin');
        $this->assertNotEmpty($token);

        // Process queued email job
        $job = $this->queue->pop('emails');
        $this->assertNotNull($job);
        (new SendMailJob())->handle($job->getData(), Container::getInstance());

        // Verify email logged
        $logContent = file_get_contents($this->tempLogPath);
        $this->assertStringContainsString('budi@klinik.local', (string) $logContent);
        $this->assertStringContainsString('Undangan Bergabung ke Klinik Sejahtera', (string) $logContent);

        // Verify valid token
        $invitation = $this->lifecycle->verifyInvitation($token);
        $this->assertNotNull($invitation);
        $this->assertSame('budi@klinik.local', $invitation['email']);

        // Accept invitation
        $accepted = $this->lifecycle->acceptInvitation($token, $userId);
        $this->assertTrue($accepted);

        // Verify user added to tenant_user
        $membership = $this->db->selectOne(
            "SELECT role FROM tenant_user WHERE tenant_id = ? AND user_id = ?",
            [$tenantId, $userId]
        );
        $this->assertNotNull($membership);
        $this->assertSame('admin', $membership['role']);

        // Second acceptance should fail (already accepted)
        $this->assertFalse($this->lifecycle->acceptInvitation($token, $userId));
    }

    public function test_account_lifecycle_password_reset_flow(): void
    {
        // Create user
        $userId = (int) $this->db->insert('users', [
            'name' => 'Ahmad Santoso',
            'email' => 'ahmad@example.com',
            'password_hash' => password_hash('oldpassword123', PASSWORD_DEFAULT),
        ]);

        // Request password reset
        $token = $this->lifecycle->sendPasswordResetLink('ahmad@example.com');
        $this->assertNotNull($token);

        // Process queued email job
        $job = $this->queue->pop('emails');
        $this->assertNotNull($job);
        (new SendMailJob())->handle($job->getData(), Container::getInstance());

        // Verify email was dispatched
        $logContent = file_get_contents($this->tempLogPath);
        $this->assertStringContainsString('ahmad@example.com', (string) $logContent);
        $this->assertStringContainsString('Instruksi Reset Password Akun', (string) $logContent);

        // Verify token
        $email = $this->lifecycle->verifyPasswordResetToken($token);
        $this->assertSame('ahmad@example.com', $email);

        // Complete reset
        $resetSuccess = $this->lifecycle->resetPassword($token, 'newSuperSecretPass2026!');
        $this->assertTrue($resetSuccess);

        // Verify new password works
        $user = $this->db->selectOne("SELECT password_hash FROM users WHERE id = ?", [$userId]);
        $this->assertTrue(password_verify('newSuperSecretPass2026!', $user['password_hash']));
        $this->assertFalse(password_verify('oldpassword123', $user['password_hash']));

        // Token is consumed and no longer valid
        $this->assertNull($this->lifecycle->verifyPasswordResetToken($token));
    }

    public function test_global_mailer_helper(): void
    {
        $this->assertInstanceOf(Mailer::class, mailer());
    }
}
