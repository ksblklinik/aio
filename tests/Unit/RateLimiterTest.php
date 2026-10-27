<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Container;
use Core\Http\Request;
use Core\Http\Response;
use Core\Middleware\ThrottleRequests;
use Core\Router;
use PHPUnit\Framework\TestCase;

class RateLimiterTest extends TestCase
{
    private string $storageDir;
    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageDir = sys_get_temp_dir() . '/aio_ratelimit_test_' . bin2hex(random_bytes(6));
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0777, true);
        }

        $this->container = new Container();
        $this->container->singleton(ThrottleRequests::class, function () {
            return new ThrottleRequests($this->storageDir);
        });
    }

    protected function tearDown(): void
    {
        // Cleanup temp directory
        if (is_dir($this->storageDir)) {
            $files = glob($this->storageDir . '/*');
            if ($files) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
            rmdir($this->storageDir);
        }

        parent::tearDown();
    }

    public function test_allows_requests_under_limit(): void
    {
        $limiter = new ThrottleRequests($this->storageDir);

        $request = new Request('GET', '/api/test', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        $next = fn(Request $r) => Response::json(['status' => 'ok']);

        // First attempt (max: 3, decay: 1 minute)
        $response = $limiter->handle($request, $next, 3, 1);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('3', $response->getHeader('X-RateLimit-Limit'));
        $this->assertSame('2', $response->getHeader('X-RateLimit-Remaining'));

        // Second attempt
        $response = $limiter->handle($request, $next, 3, 1);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('1', $response->getHeader('X-RateLimit-Remaining'));

        // Third attempt
        $response = $limiter->handle($request, $next, 3, 1);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('0', $response->getHeader('X-RateLimit-Remaining'));
    }

    public function test_blocks_requests_exceeding_limit_with_429(): void
    {
        $limiter = new ThrottleRequests($this->storageDir);

        $request = new Request('POST', '/login', ['accept' => 'application/json'], [], [], [
            'REMOTE_ADDR' => '192.168.1.50',
        ]);

        $next = fn(Request $r) => Response::json(['status' => 'ok']);

        // Max 2 attempts
        $limiter->handle($request, $next, 2, 1);
        $limiter->handle($request, $next, 2, 1);

        // 3rd attempt exceeds limit
        $response = $limiter->handle($request, $next, 2, 1);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('0', $response->getHeader('X-RateLimit-Remaining'));
        $this->assertNotNull($response->getHeader('Retry-After'));

        $body = json_decode($response->getContent(), true);
        $this->assertSame('Too Many Requests', $body['error']);
        $this->assertStringContainsString('Terlalu banyak permintaan', $body['message']);
    }

    public function test_distinct_clients_have_isolated_limits(): void
    {
        $limiter = new ThrottleRequests($this->storageDir);

        $clientA = new Request('GET', '/api/resource', [], [], [], [
            'REMOTE_ADDR' => '10.0.0.1',
        ]);

        $clientB = new Request('GET', '/api/resource', [], [], [], [
            'REMOTE_ADDR' => '10.0.0.2',
        ]);

        $next = fn(Request $r) => Response::json(['status' => 'ok']);

        // Client A consumes 2 attempts
        $limiter->handle($clientA, $next, 2, 1);
        $limiter->handle($clientA, $next, 2, 1);
        $blockedA = $limiter->handle($clientA, $next, 2, 1);
        $this->assertSame(429, $blockedA->getStatusCode());

        // Client B is unaffected
        $allowedB = $limiter->handle($clientB, $next, 2, 1);
        $this->assertSame(200, $allowedB->getStatusCode());
        $this->assertSame('1', $allowedB->getHeader('X-RateLimit-Remaining'));
    }

    public function test_router_executes_throttle_middleware_from_string(): void
    {
        $router = new Router($this->container);

        $router->get('/test-throttled', function () {
            return Response::json(['success' => true]);
        }, ['throttle:2,1']);

        $request = new Request('GET', '/test-throttled', ['accept' => 'application/json'], [], [], [
            'REMOTE_ADDR' => '127.0.0.100',
        ]);

        $res1 = $router->dispatch($request);
        $this->assertSame(200, $res1->getStatusCode());
        $this->assertSame('1', $res1->getHeader('X-RateLimit-Remaining'));

        $res2 = $router->dispatch($request);
        $this->assertSame(200, $res2->getStatusCode());
        $this->assertSame('0', $res2->getHeader('X-RateLimit-Remaining'));

        $res3 = $router->dispatch($request);
        $this->assertSame(429, $res3->getStatusCode());
    }
}
