<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;
use Core\Middleware\VerifyCsrfToken;
use PHPUnit\Framework\TestCase;

class MiddlewareTest extends TestCase
{
    private Session $session;

    protected function setUp(): void
    {
        $this->session = new Session();
        $this->session->startArrayDriver();
    }

    public function test_session_flash_and_retrieve(): void
    {
        $this->session->set('user_id', 99);
        $this->assertSame(99, $this->session->get('user_id'));

        $this->session->flash('alert', 'Welcome back!');
        $this->assertSame('Welcome back!', $this->session->getFlash('alert'));
    }

    public function test_csrf_middleware_allows_safe_methods(): void
    {
        $middleware = new VerifyCsrfToken($this->session);
        $request = new Request('GET', '/profile');

        $response = $middleware->handle($request, fn() => Response::html('Safe'));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_csrf_middleware_blocks_invalid_token_on_post(): void
    {
        $this->session->set('_token', 'valid-secret-token');
        $middleware = new VerifyCsrfToken($this->session);

        // Request with missing or wrong token
        $request = new Request('POST', '/create', bodyParams: ['_token' => 'wrong-token']);

        $response = $middleware->handle($request, fn() => Response::html('Success'));
        $this->assertSame(419, $response->getStatusCode());
    }

    public function test_csrf_middleware_passes_with_valid_header(): void
    {
        $this->session->set('_token', 'valid-secret-token');
        $middleware = new VerifyCsrfToken($this->session);

        $request = new Request('POST', '/create', headers: ['x-csrf-token' => 'valid-secret-token']);

        $response = $middleware->handle($request, fn() => Response::html('Created'));
        $this->assertSame(200, $response->getStatusCode());
    }
}
