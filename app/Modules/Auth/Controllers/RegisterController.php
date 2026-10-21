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

class RegisterController
{
    public function __construct(
        private AuthService $auth,
        private Session $session,
        private Inertia $inertia,
        private Connection $db
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
        $name = trim((string) $request->input('name', ''));
        $email = trim(strtolower((string) $request->input('email', '')));
        $password = (string) $request->input('password', '');
        $workspaceName = trim((string) $request->input('workspace_name', ''));

        if ($name === '' || $email === '' || $password === '' || $workspaceName === '') {
            $this->session->flash('error', 'Semua kolom registrasi wajib diisi.');
            return Response::redirect('/register');
        }

        if (strlen($password) < 6) {
            $this->session->flash('error', 'Kata sandi minimal harus 6 karakter.');
            return Response::redirect('/register');
        }

        $existing = User::findByEmail($this->db, $email);
        if ($existing) {
            $this->session->flash('error', 'Alamat email ini sudah terdaftar.');
            return Response::redirect('/register');
        }

        $this->auth->register($name, $email, $password, $workspaceName);

        $this->session->flash('success', "Workspace [{$workspaceName}] berhasil dibuat! Selamat datang di aio.");
        return Response::redirect('/dashboard');
    }
}
