<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Auth\Controllers\ForgotPasswordController;
use App\Modules\Auth\Controllers\ResetPasswordController;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Workspaces\Controllers\InvitationController;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use Core\Http\Request;
use Core\Http\Session;
use Core\Inertia\Inertia;
use Core\Mail\AccountLifecycleService;
use Core\Mail\LogMailerDriver;
use Core\Mail\Mailer;
use PHPUnit\Framework\TestCase;

class AuthLifecycleUiTest extends TestCase
{
    private Connection $db;
    private Session $session;
    private TenantContext $tenantContext;
    private AuthService $auth;
    private Mailer $mailer;
    private AccountLifecycleService $lifecycle;
    private Inertia $inertia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new Connection('sqlite::memory:');
        $migrator = new Migrator($this->db);
        $migrator->runMigrationsFrom(dirname(__DIR__, 2) . '/database/migrations');

        $this->session = new Session();
        $this->session->startArrayDriver();

        $this->tenantContext = new TenantContext();
        $this->auth = new AuthService($this->db, $this->session, $this->tenantContext);

        $logPath = sys_get_temp_dir() . '/aio_test_mail_' . bin2hex(random_bytes(4)) . '.log';
        $this->mailer = new Mailer(new LogMailerDriver($logPath));
        $this->lifecycle = new AccountLifecycleService($this->db, $this->mailer);

        $this->inertia = Inertia::getInstance();
    }

    public function test_forgot_password_form_renders(): void
    {
        $controller = new ForgotPasswordController($this->lifecycle, $this->session, $this->inertia);
        $request = new Request('GET', '/forgot-password');

        $response = $controller->showLinkRequestForm($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Auth\/ForgotPassword', $response->getBody());
    }

    public function test_forgot_password_sends_email_and_flashes_success(): void
    {
        // Seed user
        $this->db->insert('users', [
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'password_hash' => password_hash('password123', PASSWORD_DEFAULT),
        ]);

        $controller = new ForgotPasswordController($this->lifecycle, $this->session, $this->inertia);
        $request = new Request('POST', '/forgot-password', [], [], [
            'email' => 'budi@example.com',
        ]);

        $response = $controller->sendResetLinkEmail($request);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertNotNull($this->session->getFlash('success'));

        // Verify password_resets record exists
        $resetRecord = $this->db->selectOne("SELECT * FROM password_resets WHERE email = ?", ['budi@example.com']);
        $this->assertNotNull($resetRecord);
    }

    public function test_reset_password_form_verifies_token(): void
    {
        $this->db->insert('users', [
            'name' => 'Siti Rahma',
            'email' => 'siti@example.com',
            'password_hash' => password_hash('oldpassword', PASSWORD_DEFAULT),
        ]);

        $token = $this->lifecycle->sendPasswordResetLink('siti@example.com');
        $controller = new ResetPasswordController($this->lifecycle, $this->session, $this->inertia);

        // Valid token
        $validRequest = new Request('GET', "/reset-password?token={$token}", [], ['token' => $token]);
        $response = $controller->showResetForm($validRequest);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('siti@example.com', $response->getBody());

        // Invalid token
        $invalidRequest = new Request('GET', "/reset-password?token=invalid_token_123", [], ['token' => 'invalid_token_123']);
        $responseInvalid = $controller->showResetForm($invalidRequest);
        $this->assertSame(200, $responseInvalid->getStatusCode());
        $this->assertStringContainsString('"isValid":false', htmlspecialchars_decode($responseInvalid->getBody()));
    }

    public function test_reset_password_updates_user_password(): void
    {
        $this->db->insert('users', [
            'name' => 'Andi Wijaya',
            'email' => 'andi@example.com',
            'password_hash' => password_hash('old_secret', PASSWORD_DEFAULT),
        ]);

        $token = $this->lifecycle->sendPasswordResetLink('andi@example.com');
        $controller = new ResetPasswordController($this->lifecycle, $this->session, $this->inertia);

        $request = new Request('POST', '/reset-password', [], [], [
            'token' => $token,
            'password' => 'new_secret_123',
            'password_confirmation' => 'new_secret_123',
        ]);

        $response = $controller->reset($request);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeader('Location'));
        $this->assertNotNull($this->session->getFlash('success'));

        // Verify user can login with new password
        $userRow = $this->db->selectOne("SELECT password_hash FROM users WHERE email = ?", ['andi@example.com']);
        $this->assertTrue(password_verify('new_secret_123', $userRow['password_hash']));

        // Verify token deleted
        $tokenRow = $this->db->selectOne("SELECT * FROM password_resets WHERE token = ?", [$token]);
        $this->assertNull($tokenRow);
    }

    public function test_invitation_form_and_acceptance(): void
    {
        $tenantId = (int) $this->db->insert('tenants', [
            'name' => 'Solusi Digital',
            'slug' => 'solusi-digital',
            'plan' => 'pro',
        ]);

        $userId = (int) $this->db->insert('users', [
            'name' => 'Member Baru',
            'email' => 'member@example.com',
            'password_hash' => password_hash('secret123', PASSWORD_DEFAULT),
        ]);

        $token = $this->lifecycle->inviteMember($tenantId, 'member@example.com', 'member');
        $controller = new InvitationController($this->lifecycle, $this->auth, $this->session, $this->inertia, $this->db);

        // 1. Show accept form
        $request = new Request('GET', "/invitations/accept?token={$token}", [], ['token' => $token]);
        $response = $controller->showAcceptForm($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Solusi Digital', $response->getBody());

        // 2. Accept without login -> redirects to /login
        $acceptReq = new Request('POST', '/invitations/accept', [], [], ['token' => $token]);
        $guestRes = $controller->accept($acceptReq);
        $this->assertSame(302, $guestRes->getStatusCode());
        $this->assertSame('/login', $guestRes->getHeader('Location'));

        // 3. Login user then accept
        $this->auth->loginUsingId($userId);
        $authRes = $controller->accept($acceptReq);
        $this->assertSame(302, $authRes->getStatusCode());
        $this->assertSame('/dashboard', $authRes->getHeader('Location'));

        // Verify member joined tenant
        $memberRow = $this->db->selectOne("SELECT * FROM tenant_user WHERE tenant_id = ? AND user_id = ?", [$tenantId, $userId]);
        $this->assertNotNull($memberRow);
        $this->assertSame('member', $memberRow['role']);
    }
}
