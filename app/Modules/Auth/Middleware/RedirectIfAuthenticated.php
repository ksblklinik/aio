<?php

declare(strict_types=1);

namespace App\Modules\Auth\Middleware;

use App\Modules\Auth\Services\AuthService;
use Core\Http\Request;
use Core\Http\Response;

class RedirectIfAuthenticated
{
    public function __construct(private AuthService $auth)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        if ($this->auth->check()) {
            return Response::redirect('/dashboard');
        }

        return $next($request);
    }
}
