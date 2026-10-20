<?php

declare(strict_types=1);

namespace Core\Middleware;

use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;

class StartSession
{
    public function __construct(private ?Session $session = null)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $session = $this->session ?? (function_exists('app') ? app(Session::class) : new Session());
        $session->start();

        $response = $next($request);

        // Pasang cookie XSRF-TOKEN untuk client SPA / Inertia
        if ($session->token()) {
            $isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
            setcookie('XSRF-TOKEN', $session->token(), [
                'expires' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => $isSecure,
                'httponly' => false, // Diperbolehkan dibaca JS untuk header X-XSRF-TOKEN
                'samesite' => 'Lax',
            ]);
        }

        return $response;
    }
}
