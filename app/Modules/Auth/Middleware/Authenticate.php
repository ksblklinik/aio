<?php

declare(strict_types=1);

namespace App\Modules\Auth\Middleware;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Workspaces\Models\User;
use Core\Http\Request;
use Core\Http\Response;

class Authenticate
{
    public function __construct(
        private AuthService $auth,
        private ?bool $devAutoLogin = null,
        private ?string $env = null
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $isLocal = ($this->env ?? $_ENV['APP_ENV'] ?? 'local') === 'local';
        $allowAutoLogin = $this->devAutoLogin ?? (($_ENV['DEV_AUTO_LOGIN'] ?? 'true') === 'true');

        // Check if dev auto-login applies
        if (!$this->auth->check() && $isLocal && $allowAutoLogin) {
            // Find first user (usually demo owner) to auto-login
            $db = $request->input('__db'); // or resolved from container
            // Attempt to login user ID 1
            $this->performDevAutoLogin();
        }

        if (!$this->auth->check()) {
            if ($request->hasHeader('X-Inertia') || $request->isMethod('POST')) {
                return new Response(json_encode(['error' => 'Unauthenticated']), 401, ['Content-Type' => 'application/json']);
            }
            return Response::redirect('/login');
        }

        // Ensure active tenant context is loaded
        $this->auth->currentTenant();

        return $next($request);
    }

    private function performDevAutoLogin(): void
    {
        // Try user 1
        $ref = new \ReflectionClass($this->auth);
        $dbProp = $ref->getProperty('db');
        $dbProp->setAccessible(true);
        $db = $dbProp->getValue($this->auth);

        $firstUser = User::findById($db, 1);
        if ($firstUser) {
            $this->auth->login($firstUser);
        } else {
            // Fallback: pick any user
            $row = $db->selectOne("SELECT * FROM users LIMIT 1");
            if ($row) {
                $user = User::fromArray($row);
                $this->auth->login($user);
            }
        }
    }
}
