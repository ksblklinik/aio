<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Auth\Middleware\Authenticate;
use App\Modules\Auth\Middleware\RedirectIfAuthenticated;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Workspaces\Middleware\EnsureRole;
use App\Modules\Workspaces\Models\Tenant;
use App\Modules\Workspaces\Models\User;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;
use PHPUnit\Framework\TestCase;

class AuthMiddlewareTest extends TestCase
{
    private Connection $db;
    private Session $session;
    private TenantContext $tenantContext;
    private AuthService $auth;

    protected function setUp(): void
    {
        $this->db = new Connection('sqlite::memory:');
        $migrator = new Migrator($this->db);
        $migrator->runMigrationsFrom(__DIR__ . '/../../database/migrations');

        $this->session = new Session();
        $this->tenantContext = new TenantContext();
        $this->auth = new AuthService($this->db, $this->session, $this->tenantContext);
    }

    public function test_authenticate_redirects_unauthenticated_to_login(): void
    {
        $middleware = new Authenticate($this->auth, devAutoLogin: false);
        $request = new Request('GET', '/dashboard');

        $response = $middleware->handle($request, function ($req) {
            return new Response('Should not reach here');
        });

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeader('Location'));
    }

    public function test_authenticate_auto_logins_when_dev_mode_active(): void
    {
        // Seed user and tenant for dev
        $user = User::create($this->db, ['name' => 'Dev Owner', 'email' => 'dev@aio.test', 'password' => 'secret']);
        $tenant = Tenant::create($this->db, ['name' => 'Dev Workspace', 'slug' => 'dev-ws']);
        $tenant->addMember($this->db, (int) $user->id, 'owner');

        $middleware = new Authenticate($this->auth, devAutoLogin: true, env: 'local');
        $request = new Request('GET', '/dashboard');

        $reached = false;
        $response = $middleware->handle($request, function ($req) use (&$reached) {
            $reached = true;
            return new Response('Reached Dashboard');
        });

        $this->assertTrue($reached);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($this->auth->check());
        $this->assertSame('owner', $this->auth->currentRole());
    }

    public function test_redirect_if_authenticated_redirects_logged_in_user_to_dashboard(): void
    {
        $user = User::create($this->db, ['name' => 'User', 'email' => 'u@aio.test', 'password' => 'secret']);
        $this->auth->login($user);

        $middleware = new RedirectIfAuthenticated($this->auth);
        $request = new Request('GET', '/login');

        $response = $middleware->handle($request, function ($req) {
            return new Response('Login Form');
        });

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/dashboard', $response->getHeader('Location'));
    }

    public function test_ensure_role_blocks_unauthorized_roles(): void
    {
        $user = User::create($this->db, ['name' => 'Member User', 'email' => 'm@aio.test', 'password' => 'secret']);
        $tenant = Tenant::create($this->db, ['name' => 'Workspace', 'slug' => 'ws']);
        $tenant->addMember($this->db, (int) $user->id, 'member');

        $this->auth->login($user, (int) $tenant->id);

        $middleware = new EnsureRole($this->auth, ['owner', 'admin']);
        $request = new Request('GET', '/settings');

        $response = $middleware->handle($request, function ($req) {
            return new Response('Admin Area');
        });

        $this->assertSame(403, $response->getStatusCode());
    }
}
