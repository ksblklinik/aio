<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Services\AuthService;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;
use Core\Inertia\Inertia;
use Core\Validation\ValidatesRequests;

class LoginController
{
    use ValidatesRequests;
    public function __construct(
        private AuthService $auth,
        private Session $session,
        private Inertia $inertia,
        private Connection $db,
        private ?\Core\Audit\AuditLogger $auditLogger = null
    ) {
    }

    public function showLoginForm(Request $request): Response
    {
        // Get demo users if local
        $demoUsers = [];
        $isLocal = ($_ENV['APP_ENV'] ?? 'local') === 'local';
        if ($isLocal) {
            $demoUsers = $this->db->selectAll(
                "SELECT u.id as user_id, u.name as user_name, u.email, t.id as tenant_id, t.name as tenant_name, tu.role 
                 FROM tenant_user tu 
                 JOIN users u ON u.id = tu.user_id 
                 JOIN tenants t ON t.id = tu.tenant_id 
                 ORDER BY tu.id ASC"
            );
        }

        return $this->inertia->render('Auth/Login', [
            'demoUsers' => $demoUsers,
            'isLocal' => $isLocal,
            'flash' => [
                'error' => $this->session->getFlash('error'),
                'success' => $this->session->getFlash('success'),
            ],
        ]);
    }

    public function login(Request $request): Response
    {
        $validated = $this->validate($request, [
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (!$this->auth->attempt($validated['email'], $validated['password'])) {
            $this->session->flash('errors', ['email' => 'Email atau kata sandi tidak cocok.']);
            $this->session->flash('error', 'Email atau kata sandi tidak cocok.');
            return Response::redirect('/login');
        }

        $user = $this->auth->user();
        $this->auditLogger?->log(
            action: 'auth.login',
            description: "Pengguna {$user?->name} berhasil masuk sistem",
            entityType: 'user',
            entityId: $user ? (string) $user->id : null
        );

        $this->session->flash('success', 'Selamat datang kembali!');
        return Response::redirect('/dashboard');
    }

    public function logout(Request $request): Response
    {
        $user = $this->auth->user();
        $this->auditLogger?->log(
            action: 'auth.logout',
            description: "Pengguna {$user?->name} keluar dari sistem",
            entityType: 'user',
            entityId: $user ? (string) $user->id : null
        );

        $this->auth->logout();
        $this->session->flash('success', 'Anda telah berhasil keluar.');
        return Response::redirect('/login');
    }
}
