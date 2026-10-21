<?php

declare(strict_types=1);

namespace App\Modules\Workspaces\Middleware;

use App\Modules\Auth\Services\AuthService;
use Core\Http\Request;
use Core\Http\Response;

class EnsureRole
{
    /**
     * @param array<string>|string $allowedRoles
     */
    public function __construct(
        private AuthService $auth,
        private array|string $allowedRoles = ['owner', 'admin']
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $currentRole = $this->auth->currentRole();
        $allowed = is_array($this->allowedRoles) ? $this->allowedRoles : [$this->allowedRoles];

        if ($currentRole === null || !in_array($currentRole, $allowed, true)) {
            return new Response(
                "Akses ditolak: Memerlukan izin role [" . implode(', ', $allowed) . "].",
                403,
                ['Content-Type' => 'text/plain']
            );
        }

        return $next($request);
    }
}
