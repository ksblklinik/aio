<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Workspaces\Models\User;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;
use Core\Inertia\Inertia;
use Core\Validation\ValidatesRequests;

class RegisterController
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

    public function showRegistrationForm(Request $request): Response
    {
        return $this->inertia->render('Auth/Register', [
            'flash' => [
                'error' => $this->session->getFlash('error'),
            ],
        ]);
    }

    public function register(Request $request): Response
    {
        $validated = $this->validate($request, [
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'workspace_name' => 'required|string|max:100',
        ]);

        $this->auth->register(
            $validated['name'],
            strtolower($validated['email']),
            $validated['password'],
            $validated['workspace_name']
        );

        $this->auditLogger?->log(
            action: 'auth.register',
            description: "Pendaftaran akun baru [{$validated['name']}] dan workspace [{$validated['workspace_name']}]",
            entityType: 'tenant',
            entityId: null,
            properties: [
                'name' => $validated['name'],
                'email' => strtolower($validated['email']),
                'workspace' => $validated['workspace_name'],
            ]
        );

        $this->session->flash('success', "Workspace [{$validated['workspace_name']}] berhasil dibuat! Selamat datang di aio.");
        return Response::redirect('/dashboard');
    }
}
