<?php

declare(strict_types=1);

namespace App\Modules\Workspaces\Controllers;

use App\Modules\Auth\Services\AuthService;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;
use Core\Inertia\Inertia;
use Core\Mail\AccountLifecycleService;
use Core\Validation\ValidatesRequests;

class InvitationController
{
    use ValidatesRequests;

    public function __construct(
        private AccountLifecycleService $lifecycle,
        private AuthService $auth,
        private Session $session,
        private Inertia $inertia,
        private Connection $db
    ) {
    }

    public function showAcceptForm(Request $request): Response
    {
        $token = (string) $request->query('token', '');
        $invitation = $token !== '' ? $this->lifecycle->verifyInvitation($token) : null;
        $currentUser = $this->auth->user();

        $tenant = null;
        if ($invitation) {
            $tenant = $this->db->selectOne(
                "SELECT id, name, slug FROM tenants WHERE id = ? LIMIT 1",
                [$invitation['tenant_id']]
            );
        }

        return $this->inertia->render('Workspaces/AcceptInvitation', [
            'token' => $token,
            'invitation' => $invitation,
            'tenant' => $tenant,
            'currentUser' => $currentUser ? [
                'id' => $currentUser->id,
                'name' => $currentUser->name,
                'email' => $currentUser->email,
            ] : null,
            'isValid' => $invitation !== null,
            'flash' => [
                'error' => $this->session->getFlash('error'),
                'success' => $this->session->getFlash('success'),
            ],
        ]);
    }

    public function accept(Request $request): Response
    {
        $validated = $this->validate($request, [
            'token' => 'required',
        ]);

        $token = (string) $validated['token'];
        $user = $this->auth->user();

        if (!$user) {
            $this->session->flash('error', 'Silakan masuk atau buat akun terlebih dahulu untuk menerima undangan ini.');
            if ($request->wantsJson() && !$request->isInertia()) {
                return Response::json(['error' => 'Unauthenticated'], 401);
            }
            return Response::redirect('/login');
        }

        $invitation = $this->lifecycle->verifyInvitation($token);
        if (!$invitation) {
            $this->session->flash('error', 'Tautan undangan tidak valid atau telah kedaluwarsa.');
            if ($request->wantsJson() && !$request->isInertia()) {
                return Response::json(['error' => 'Tautan undangan tidak valid atau telah kedaluwarsa.'], 400);
            }
            return Response::redirect('/dashboard');
        }

        $tenantId = (int) $invitation['tenant_id'];
        $success = $this->lifecycle->acceptInvitation($token, (int) $user->id);

        if (!$success) {
            $this->session->flash('error', 'Gagal memproses penerimaan undangan.');
            return Response::redirect('/dashboard');
        }

        // Beralih ke tenant yang baru saja diterima
        $this->auth->switchTenant($tenantId);

        $this->session->flash('success', 'Selamat! Anda telah resmi bergabung ke workspace.');

        if ($request->wantsJson() && !$request->isInertia()) {
            return Response::json(['message' => 'Undangan berhasil diterima.', 'tenant_id' => $tenantId]);
        }

        return Response::redirect('/dashboard');
    }
}
