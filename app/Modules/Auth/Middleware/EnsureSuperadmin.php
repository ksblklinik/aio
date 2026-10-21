<?php

declare(strict_types=1);

namespace App\Modules\Auth\Middleware;

use App\Modules\Auth\Services\AuthService;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;

class EnsureSuperadmin
{
    public function __construct(
        private AuthService $auth,
        private Session $session
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (!$this->auth->check()) {
            if ($request->isJson() && !$request->isInertia()) {
                return Response::json(['error' => 'Unauthenticated'], 401);
            }
            return Response::redirect('/login');
        }

        if (!$this->auth->isSuperadmin()) {
            $isJson = $request->isJson() || str_contains((string) $request->header('accept', ''), 'application/json');
            if ($isJson && !$request->isInertia()) {
                return Response::json([
                    'error' => 'Forbidden',
                    'message' => 'Akses ditolak: Hanya Superadmin Platform yang dapat mengakses sumber daya ini.',
                ], 403);
            }

            $this->session->flash('error', 'Akses ditolak: Otoritas Platform Superadmin diperlukan.');
            return Response::redirect('/dashboard');
        }

        return $next($request);
    }
}
