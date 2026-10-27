<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;
use Core\Inertia\Inertia;
use Core\Mail\AccountLifecycleService;
use Core\Validation\ValidatesRequests;

class ResetPasswordController
{
    use ValidatesRequests;

    public function __construct(
        private AccountLifecycleService $lifecycle,
        private Session $session,
        private Inertia $inertia
    ) {
    }

    public function showResetForm(Request $request): Response
    {
        $token = (string) $request->query('token', '');
        $email = $token !== '' ? $this->lifecycle->verifyPasswordResetToken($token) : null;
        $isValid = $email !== null;

        return $this->inertia->render('Auth/ResetPassword', [
            'token' => $token,
            'email' => $email ?? '',
            'isValid' => $isValid,
            'flash' => [
                'error' => $this->session->getFlash('error'),
                'success' => $this->session->getFlash('success'),
            ],
        ]);
    }

    public function reset(Request $request): Response
    {
        $validated = $this->validate($request, [
            'token' => 'required',
            'password' => 'required|min:8|confirmed',
            'password_confirmation' => 'required',
        ]);

        $token = (string) $validated['token'];
        $newPassword = (string) $validated['password'];

        $success = $this->lifecycle->resetPassword($token, $newPassword);

        if (!$success) {
            $this->session->flash('error', 'Tautan reset kata sandi tidak valid atau telah kedaluwarsa.');
            if ($request->wantsJson() && !$request->isInertia()) {
                return Response::json(['error' => 'Tautan reset kata sandi tidak valid atau telah kedaluwarsa.'], 400);
            }
            return Response::redirect('/forgot-password');
        }

        $this->session->flash('success', 'Kata sandi berhasil diperbarui. Silakan masuk dengan kata sandi baru Anda.');

        if ($request->wantsJson() && !$request->isInertia()) {
            return Response::json(['message' => 'Kata sandi berhasil diperbarui.']);
        }

        return Response::redirect('/login');
    }
}
