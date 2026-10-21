<?php

declare(strict_types=1);

namespace Core\Middleware;

use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;

class VerifyCsrfToken
{
    public function __construct(private ?Session $session = null)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        // Metode HTTP baca (safe methods) dikecualikan
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        // Endpoint REST API (/api/*) dikecualikan dari proteksi CSRF sesi browser
        if (str_starts_with($request->path(), '/api/')) {
            return $next($request);
        }

        $session = $this->session ?? (function_exists('app') ? app(Session::class) : new Session());
        $sessionToken = $session->token();

        $headerToken = $request->header('x-csrf-token') ?? $request->header('x-xsrf-token');
        $bodyToken = $request->input('_token');

        $token = $headerToken ?: $bodyToken;

        if (!$token || !hash_equals($sessionToken, (string) $token)) {
            return Response::json([
                'error' => 'CSRF Token Mismatch / Page Expired',
                'message' => 'Token keamanan sesi tidak valid atau telah kedaluwarsa.',
            ], 419);
        }

        return $next($request);
    }
}
