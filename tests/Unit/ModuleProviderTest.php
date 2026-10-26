<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Console\Command;
use Core\Events\EventDispatcher;
use Core\Foundation\Application;
use Core\Http\Request;
use Core\Http\Response;
use Core\Modules\DefaultModuleProvider;
use Core\Modules\ModuleManager;
use Core\Modules\ModuleProvider;
use Core\Modules\ModuleProviderInterface;
use Core\Router;
use PHPUnit\Framework\TestCase;

class DummyEvent
{
    public function __construct(public array $data = [])
    {
    }
}

class DummyModuleCommand extends Command
{
    protected string $signature = 'dummy:run';
    protected string $description = 'Dummy test command from module';

    public function handle(array $arguments = [], array $options = []): int
    {
        $this->info('dummy executed');
        return 0;
    }
}

class SampleTestServiceProvider extends ModuleProvider
{
    public static bool $registered = false;
    public static bool $booted = false;
    public static bool $eventHandled = false;

    public function register(): void
    {
        self::$registered = true;
        $this->singleton('dummy.service', fn() => 'dummy_value');
    }

    public function boot(): void
    {
        self::$booted = true;

        $this->listen(DummyEvent::class, function ($event) {
            self::$eventHandled = true;
        });

        $this->command(DummyModuleCommand::class);

        $this->shareInertia([
            'dummy_shared' => 'yes',
        ]);
    }
}

class ModuleProviderTest extends TestCase
{
    private Application $app;
    private string $tempModulesDir;

    protected function setUp(): void
    {
        parent::setUp();
        SampleTestServiceProvider::$registered = false;
        SampleTestServiceProvider::$booted = false;
        SampleTestServiceProvider::$eventHandled = false;

        $this->app = new Application(dirname(__DIR__, 2));

        $this->tempModulesDir = sys_get_temp_dir() . '/aio_provider_test_' . uniqid();
        mkdir($this->tempModulesDir . '/SampleMod', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempModulesDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $p = $dir . '/' . $file;
            is_dir($p) ? $this->removeDirectory($p) : unlink($p);
        }
        rmdir($dir);
    }

    public function test_module_provider_interface_and_abstract_helpers(): void
    {
        $provider = new SampleTestServiceProvider($this->app);
        $this->assertInstanceOf(ModuleProviderInterface::class, $provider);

        $provider->register();
        $this->assertTrue(SampleTestServiceProvider::$registered);
        $this->assertSame('dummy_value', $this->app->getContainer()->get('dummy.service'));

        $provider->boot();
        $this->assertTrue(SampleTestServiceProvider::$booted);

        // Test event listener was registered via $this->listen()
        event(new DummyEvent(['test' => 123]));
        $this->assertTrue(SampleTestServiceProvider::$eventHandled);
    }

    public function test_default_module_provider_loads_routes(): void
    {
        $routesPath = $this->tempModulesDir . '/SampleMod/routes.php';
        file_put_contents($routesPath, '<?php /** @var \Core\Router $router */ $router->get("/test-default-route", fn() => \Core\Http\Response::json(["route" => "ok"]));');

        $provider = new DefaultModuleProvider($this->app, $this->tempModulesDir . '/SampleMod');
        $provider->boot();

        $router = $this->app->getContainer()->get(Router::class);
        $req = new Request('GET', '/test-default-route');
        $res = $router->dispatch($req);

        $this->assertSame(200, $res->getStatusCode());
        $data = json_decode($res->getBody(), true);
        $this->assertSame('ok', $data['route']);
    }

    public function test_module_manager_syncs_provider_and_permissions_from_module_json(): void
    {
        $manifest = [
            'id' => 'sample_mod',
            'name' => 'Sample Module',
            'version' => '1.0.0',
            'category' => 'general',
            'route' => '/sample-mod',
            'provider' => SampleTestServiceProvider::class,
            'permissions' => [
                ['key' => 'sample.view', 'name' => 'View Sample'],
                ['key' => 'sample.edit', 'name' => 'Edit Sample'],
            ],
        ];

        file_put_contents($this->tempModulesDir . '/SampleMod/module.json', json_encode($manifest, JSON_PRETTY_PRINT));

        $db = $this->app->getContainer()->get(\Core\Database\Connection::class);
        $manager = new ModuleManager($db->getPdo(), $this->tempModulesDir);

        $modules = $manager->listModules();
        $found = null;
        foreach ($modules as $m) {
            if ($m['id'] === 'sample_mod') {
                $found = $m;
                break;
            }
        }

        $this->assertNotNull($found);
        $this->assertSame(SampleTestServiceProvider::class, $found['provider']);
        $this->assertCount(2, $found['permissions']);
        $this->assertSame('sample.view', $found['permissions'][0]['key']);
    }

    public function test_cli_kernel_boots_module_commands(): void
    {
        $provider = new SampleTestServiceProvider($this->app);
        $provider->boot();

        $kernel = $this->app->createCliKernel();
        $commands = $kernel->getCommands();

        $this->assertArrayHasKey('dummy:run', $commands);
    }
}
