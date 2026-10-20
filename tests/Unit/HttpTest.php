<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Http\Request;
use Core\Http\Response;
use PHPUnit\Framework\TestCase;

class HttpTest extends TestCase
{
    public function test_request_methods_and_uri(): void
    {
        $request = new Request(
            method: 'POST',
            uri: '/dashboard?tab=analytics',
            headers: ['content-type' => 'application/json', 'x-inertia' => 'true'],
            queryParams: ['tab' => 'analytics'],
            bodyParams: ['name' => 'aio SaaS', 'tier' => 'pro']
        );

        $this->assertSame('POST', $request->method());
        $this->assertTrue($request->isMethod('POST'));
        $this->assertSame('/dashboard', $request->path());
        $this->assertSame('analytics', $request->query('tab'));
        $this->assertSame('aio SaaS', $request->input('name'));
        $this->assertSame('pro', $request->input('tier'));
        $this->assertTrue($request->isInertia());
        $this->assertTrue($request->isJson());
    }

    public function test_request_default_input_fallback(): void
    {
        $request = new Request('GET', '/profile');

        $this->assertSame('dark', $request->input('theme', 'dark'));
        $this->assertNull($request->input('non_existent'));
    }

    public function test_response_json_output(): void
    {
        $response = Response::json(['status' => 'ok', 'code' => 200], 200);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeader('Content-Type'));
        $this->assertJsonStringEqualsJsonString(
            json_encode(['status' => 'ok', 'code' => 200]),
            $response->getBody()
        );
    }

    public function test_response_redirect(): void
    {
        $response = Response::redirect('/login');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeader('Location'));
    }

    public function test_response_headers_and_status(): void
    {
        $response = new Response('Hello World', 201, ['X-Custom' => 'AIO']);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('Hello World', $response->getBody());
        $this->assertSame('AIO', $response->getHeader('X-Custom'));
    }
}
