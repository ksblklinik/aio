<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Workspaces\Models\User;
use Core\Console\Commands\CreateSuperadminCommand;
use Core\Console\Commands\ListCommand;
use Core\Console\Commands\MakeModuleCommand;
use Core\Console\Commands\MigrateCommand;
use Core\Console\Commands\ModuleListCommand;
use Core\Console\Commands\RouteListCommand;
use Core\Console\Commands\TenantListCommand;
use Core\Console\Kernel;
use Core\Container;
use Core\Database\Connection;
use Core\Database\TenantContext;
use Core\Database\TenantScope;
use Core\Http\Request;
use Core\Http\Response;
use Core\Modules\ModuleManager;
use Core\Router;
use PHPUnit\Framework\TestCase;

class ConsoleTest extends TestCase
{
    private Container $container;
    private Connection $db;
    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->db = new Connection('sqlite::memory:');
        $this->container->singleton(Connection::class, fn() => $this->db);

        $tenantContext = new TenantContext();
        $this->container->singleton(TenantContext::class, fn() => $tenantContext);

        $tenantScope = new TenantScope($tenantContext, $this->db);
        $this->container->singleton(TenantScope::class, fn() => $tenantScope);

        // Run standard migrations on in-memory DB
        $migrator = new \Core\Database\Migrator($this->db);
        $migrator->runMigrationsFrom(dirname(__DIR__, 2) . '/database/migrations');

        $moduleManager = new ModuleManager($this->db->getPdo());
        $this->container->singleton(ModuleManager::class, fn() => $moduleManager);

        $router = new Router($this->container);
        $router->get('/test-route', fn() => new Response('hello'));
        $this->container->singleton(Router::class, fn() => $router);

        $this->kernel = new Kernel($this->container);
        $this->container->singleton(Kernel::class, fn() => $this->kernel);

        $this->kernel->register(new ListCommand())
            ->register(new MigrateCommand())
            ->register(new MakeModuleCommand())
            ->register(new CreateSuperadminCommand())
            ->register(new ModuleListCommand())
            ->register(new TenantListCommand())
            ->register(new RouteListCommand());
    }

    public function test_kernel_returns_error_for_unknown_command(): void
    {
        ob_start();
        $status = $this->kernel->handle(['aio', 'unknown:command']);
        $output = ob_get_clean();

        $this->assertSame(1, $status);
        $this->assertStringContainsString("tidak dikenali", (string) $output);
    }

    public function test_list_command_displays_registered_commands(): void
    {
        ob_start();
        $status = $this->kernel->handle(['aio', 'list']);
        $output = ob_get_clean();

        $this->assertSame(0, $status);
        $this->assertStringContainsString("aio Full-Stack SaaS Framework", (string) $output);
        $this->assertStringContainsString("migrate", (string) $output);
        $this->assertStringContainsString("make:module", (string) $output);
        $this->assertStringContainsString("route:list", (string) $output);
    }

    public function test_route_list_command_displays_registered_routes(): void
    {
        ob_start();
        $status = $this->kernel->handle(['aio', 'route:list']);
        $output = ob_get_clean();

        $this->assertSame(0, $status);
        $this->assertStringContainsString("/test-route", (string) $output);
        $this->assertStringContainsString("GET", (string) $output);
    }

    public function test_tenant_list_command_displays_tenants(): void
    {
        $this->db->statement("INSERT INTO tenants (id, name, slug, plan) VALUES (1, 'Test Corp', 'testcorp', 'ENTERPRISE')");
        $this->db->statement("INSERT INTO tenant_user (tenant_id, user_id, role) VALUES (1, 10, 'OWNER')");

        ob_start();
        $status = $this->kernel->handle(['aio', 'tenant:list']);
        $output = ob_get_clean();

        $this->assertSame(0, $status);
        $this->assertStringContainsString("Test Corp", (string) $output);
        $this->assertStringContainsString("testcorp", (string) $output);
        $this->assertStringContainsString("ENTERPRISE", (string) $output);
    }

    public function test_module_list_command_displays_installed_modules(): void
    {
        ob_start();
        $status = $this->kernel->handle(['aio', 'module:list']);
        $output = ob_get_clean();

        $this->assertSame(0, $status);
        $this->assertStringContainsString("auth", (string) $output);
        $this->assertStringContainsString("modules", (string) $output);
        $this->assertStringContainsString("workspaces", (string) $output);
        $this->assertStringContainsString("projects", (string) $output);
    }

    public function test_user_create_superadmin_command_creates_new_superadmin(): void
    {
        ob_start();
        $status = $this->kernel->handle(['aio', 'user:create-superadmin', 'admin@example.com', '--name=Super Boss', '--password=secret123']);
        $output = ob_get_clean();

        $this->assertSame(0, $status);
        $this->assertStringContainsString("berhasil dibuat", (string) $output);

        $user = User::findByEmail($this->db, 'admin@example.com');
        $this->assertNotNull($user);
        $this->assertSame('Super Boss', $user->name);
        $this->assertTrue($user->isSuperadmin);
        $this->assertTrue(password_verify('secret123', $user->passwordHash));
    }

    public function test_user_create_superadmin_promotes_existing_user(): void
    {
        $this->db->statement("INSERT INTO users (id, name, email, password_hash, is_superadmin) VALUES (1, 'Regular User', 'regular@example.com', 'hash', 0)");

        ob_start();
        $status = $this->kernel->handle(['aio', 'user:create-superadmin', 'regular@example.com']);
        $output = ob_get_clean();

        $this->assertSame(0, $status);
        $this->assertStringContainsString("berhasil dipromosikan", (string) $output);

        $user = User::findByEmail($this->db, 'regular@example.com');
        $this->assertNotNull($user);
        $this->assertTrue($user->isSuperadmin);
    }

    public function test_migrate_command_executes_cleanly(): void
    {
        ob_start();
        $status = $this->kernel->handle(['aio', 'migrate']);
        $output = ob_get_clean();

        $this->assertSame(0, $status);
    }

    public function test_make_module_command_validates_required_name(): void
    {
        ob_start();
        $status = $this->kernel->handle(['aio', 'make:module']);
        $output = ob_get_clean();

        $this->assertSame(1, $status);
        $this->assertStringContainsString("Nama modul wajib diisi", (string) $output);
    }
}
