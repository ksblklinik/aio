<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Container;
use Core\Http\Request;
use Core\Http\Response;
use Core\Router;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $container = new Container();
        Container::setInstance($container);
        $this->router = new Router($container);
    }

    public function test_can_dispatch_simple_get_route(): void
    {
        $this->router->get('/health', fn() => Response::json(['status' => 'healthy']));

        $request = new Request('GET', '/health');
        $response = $this->router->dispatch($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('healthy', $response->getBody());
    }

    public function test_can_dispatch_route_with_parameters(): void
    {
        $this->router->get('/users/{id}', function (Request $req, string $id) {
            return Response::json(['user_id' => $id]);
        });

        $request = new Request('GET', '/users/42');
        $response = $this->router->dispatch($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('"user_id":"42"', $response->getBody());
    }

    public function test_can_execute_middleware_pipeline(): void
    {
        $executed = [];

        $middleware = function (Request $request, callable $next) use (&$executed) {
            $executed[] = 'before';
            $response = $next($request);
            $executed[] = 'after';
            return $response;
        };

        $this->router->get('/protected', function () use (&$executed) {
            $executed[] = 'handler';
            return Response::html('OK');
        }, [$middleware]);

        $request = new Request('GET', '/protected');
        $response = $this->router->dispatch($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['before', 'handler', 'after'], $executed);
    }

    public function test_middleware_can_short_circuit_pipeline(): void
    {
        $authMiddleware = function (Request $request, callable $next) {
            if (!$request->hasHeader('Authorization')) {
                return Response::json(['error' => 'Unauthorized'], 401);
            }
            return $next($request);
        };

        $this->router->get('/secret', fn() => Response::html('Secret Data'), [$authMiddleware]);

        // Request without auth
        $reqNoAuth = new Request('GET', '/secret');
        $resNoAuth = $this->router->dispatch($reqNoAuth);
        $this->assertSame(401, $resNoAuth->getStatusCode());

        // Request with auth
        $reqAuth = new Request('GET', '/secret', ['Authorization' => 'Bearer 123']);
        $resAuth = $this->router->dispatch($reqAuth);
        $this->assertSame(200, $resAuth->getStatusCode());
    }

    public function test_returns_404_for_unregistered_routes(): void
    {
        $request = new Request('GET', '/nowhere');
        $response = $this->router->dispatch($request);

        $this->assertSame(404, $response->getStatusCode());
    }
}
