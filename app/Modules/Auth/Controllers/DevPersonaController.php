<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Workspaces\Models\User;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Response;

class DevPersonaController
{
    public function __construct(
        private AuthService $auth,
        private Connection $db
    ) {
    }

    public function switchPersona(Request $request): Response
    {
        $env = $_ENV['APP_ENV'] ?? 'local';
        if ($env === 'production') {
            return new Response('Not Found', 404);
        }

        $userId = $request->query('user_id');
        $tenantId = $request->query('tenant_id');
        $role = $request->query('role');

        if ($userId) {
            $user = User::findById($this->db, (int) $userId);
            if ($user) {
                $targetTenantId = $tenantId ? (int) $tenantId : null;
                $this->auth->login($user, $targetTenantId);
            }
        } elseif ($role === 'superadmin') {
            $row = $this->db->selectOne("SELECT id FROM users WHERE is_superadmin = 1 LIMIT 1");
            if ($row) {
                $user = User::findById($this->db, (int) $row['id']);
                if ($user) {
                    $this->auth->login($user, $tenantId ? (int) $tenantId : null);
                }
            }
        } elseif ($role) {
            // Find a user with this role
            $row = $this->db->selectOne(
                "SELECT tu.user_id, tu.tenant_id 
                 FROM tenant_user tu 
                 WHERE tu.role = ? 
                 LIMIT 1",
                [$role]
            );

            if ($row) {
                $user = User::findById($this->db, (int) $row['user_id']);
                if ($user) {
                    $this->auth->login($user, (int) $row['tenant_id']);
                }
            }
        }

        $redirectUrl = $request->query('redirect', '/dashboard');
        return Response::redirect($redirectUrl);
    }
}
