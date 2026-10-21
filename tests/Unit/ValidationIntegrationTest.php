<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Auth\Controllers\LoginController;
use App\Modules\Auth\Controllers\RegisterController;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Modules\Controllers\ModuleManagerController;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use Core\Http\Request;
use Core\Http\Session;
use Core\Inertia\Inertia;
use Core\Modules\ModuleManager;
use Core\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

class ValidationIntegrationTest extends TestCase
{
    private Connection $db;
    private Session $session;
    private AuthService $auth;
    private Inertia $inertia;

    protected function setUp(): void
    {
        $this->db = new Connection('sqlite::memory:');
        $migrator = new Migrator($this->db);
        $migrator->runMigrationsFrom(__DIR__ . '/../../database/migrations');

        $this->session = new Session();
        $tenantContext = TenantContext::getInstance();
        $this->auth = new AuthService($this->db, $this->session, $tenantContext);
        $this->inertia = new Inertia();
    }

    public function testLoginControllerRejectsInvalidEmail(): void
    {
        $controller = new LoginController($this->auth, $this->session, $this->inertia, $this->db);

        $request = new Request('POST', '/login', [], [], ['email' => 'invalid-email-string', 'password' => 'secret123']);

        try {
            $controller->login($request);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
            $this->assertStringContainsString('Format Email tidak valid', $e->errors()['email']);
            $this->assertSame('Format Email tidak valid.', $this->session->getFlash('error'));
            $this->assertArrayHasKey('email', $this->session->getFlash('errors'));
        }
    }

    public function testRegisterControllerRejectsShortPasswordAndDuplicateEmail(): void
    {
        $controller = new RegisterController($this->auth, $this->session, $this->inertia, $this->db);

        // 1. Password too short (< 6 chars)
        $request1 = new Request('POST', '/register', [], [], [
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'password' => '123',
            'workspace_name' => 'Budi Corp',
        ]);

        try {
            $controller->register($request1);
            $this->fail('Expected ValidationException for short password was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('password', $e->errors());
            $this->assertStringContainsString('Kata Sandi minimal harus 6 karakter', $e->errors()['password']);
        }

        // 2. Register valid user
        $requestValid = new Request('POST', '/register', [], [], [
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'password' => 'secret123',
            'workspace_name' => 'Budi Corp',
        ]);
        $controller->register($requestValid);

        // 3. Duplicate email registration
        $requestDuplicate = new Request('POST', '/register', [], [], [
            'name' => 'Another User',
            'email' => 'budi@example.com',
            'password' => 'secret123',
            'workspace_name' => 'Another Corp',
        ]);

        try {
            $controller->register($requestDuplicate);
            $this->fail('Expected ValidationException for duplicate email was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
            $this->assertStringContainsString('Email sudah terdaftar', $e->errors()['email']);
        }
    }

    public function testModuleManagerControllerRejectsEmptyName(): void
    {
        // Login as owner
        $this->auth->register('Owner', 'owner@example.com', 'password', 'Test Tenant');
        $moduleManager = new ModuleManager($this->db->getPdo());
        $controller = new ModuleManagerController($moduleManager, $this->auth, $this->session);

        $request = new Request('POST', '/modules/create', [], [], [
            'name' => '',
            'id' => '',
        ]);

        try {
            $controller->create($request);
            $this->fail('Expected ValidationException for empty module name was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors());
            $this->assertStringContainsString('Nama wajib diisi', $e->errors()['name']);
        }
    }
}
