<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Database\Connection;
use Core\Database\TenantScope;
use Core\Foundation\Application;
use Core\Http\Request;
use Core\Http\Response;
use Core\Router;
use Core\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

class CorePlatformHardeningTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Application(dirname(__DIR__, 2));
    }
    public function test_path_helpers(): void
    {
        $this->assertDirectoryExists(base_path());
        $this->assertSame(base_path('app'), app_path());
        $this->assertSame(base_path('storage'), storage_path());
        $this->assertSame(base_path('public'), public_path());
        $this->assertSame(base_path('config'), config_path());
        $this->assertSame(base_path('resources'), resource_path());

        $this->assertSame(base_path('storage/framework'), storage_path('framework'));
        $this->assertSame(base_path('public/build'), public_path('build'));
    }

    public function test_global_db_helper(): void
    {
        $db = db();
        $this->assertInstanceOf(Connection::class, $db);

        $scope = db('projects');
        $this->assertInstanceOf(TenantScope::class, $scope);
    }

    public function test_handle_http_returns_json_422_on_validation_failure_for_api_clients(): void
    {
        $app = new Application(dirname(__DIR__, 2));

        // Register test route that throws ValidationException
        /** @var Router $router */
        $router = $app->getContainer()->get(Router::class);
        $router->post('/api/test-validation', function () {
            throw new ValidationException(['name' => 'Nama wajib diisi.']);
        });

        $request = new Request('POST', '/api/test-validation', ['accept' => 'application/json']);
        $response = $app->handleHttp($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeader('Content-Type'));

        $body = json_decode($response->getContent(), true);
        $this->assertSame('Validation Error', $body['error']);
        $this->assertSame('Nama wajib diisi.', $body['message']);
        $this->assertSame('Nama wajib diisi.', $body['errors']['name']);
    }

    public function test_core_configurations(): void
    {
        $this->assertNotEmpty(config('mail.default'));
        $this->assertSame('log', config('mail.mailers.log.transport'));
        $this->assertSame('smtp', config('mail.mailers.smtp.transport'));
        $this->assertNotEmpty(config('mail.from.address'));

        $this->assertSame('database', config('queue.default'));
        $this->assertSame('jobs', config('queue.connections.database.table'));

        $this->assertSame('local', config('storage.default'));
        $this->assertStringContainsString('storage/app', config('storage.disks.local.root'));

        $this->assertSame('daily', config('logging.default'));
        $this->assertStringContainsString('storage/logs', config('logging.channels.daily.path'));
    }

    public function test_queue_and_notify_and_storage_helpers(): void
    {
        $queueMgr = queue();
        $this->assertInstanceOf(\Core\Queue\QueueManager::class, $queueMgr);

        $notifService = notify();
        $this->assertInstanceOf(\Core\Notification\NotificationService::class, $notifService);

        $storageMgr = storage();
        $this->assertInstanceOf(\Core\Storage\StorageManager::class, $storageMgr);
    }

    public function test_mailer_dynamic_driver(): void
    {
        /** @var \Core\Mail\Mailer $mailer */
        $mailer = $this->app->getContainer()->get(\Core\Mail\Mailer::class);
        $this->assertInstanceOf(\Core\Mail\Mailer::class, $mailer);

        $logDriver = $mailer->driver('log');
        $this->assertInstanceOf(\Core\Mail\LogMailerDriver::class, $logDriver);

        $smtpDriver = $mailer->driver('smtp');
        $this->assertInstanceOf(\Core\Mail\SmtpMailerDriver::class, $smtpDriver);
    }
}

