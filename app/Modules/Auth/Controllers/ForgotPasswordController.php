<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;
use Core\Inertia\Inertia;
use Core\Mail\AccountLifecycleService;
use Core\Validation\ValidatesRequests;

class ForgotPasswordController
{
    use ValidatesRequests;

    public function __construct(
        private AccountLifecycleService $lifecycle,
        private Session $session,
        private Inertia $inertia
    ) {
    }

    public function showLinkRequestForm(Request $request): Response
    {
        return $this->inertia->render('Auth/ForgotPassword', [
            'flash' => [
                'error' => $this->session->getFlash('error'),
                'success' => $this->session->getFlash('success'),
            ],
        ]);
    }

    public function sendResetLinkEmail(Request $request): Response
    {
        $validated = $this->validate($request, [
            'email' => 'required|email',
        ]);

        $email = (string) $validated['email'];

        // Kirim link reset (jika email tidak ada di sistem, fungsi mengembalikan null)
        $this->lifecycle->sendPasswordResetLink($email);

        // Anti-enumeration: selalu tampilkan pesan seragam demi keamanan privasi
        $message = 'Jika email terdaftar di sistem kami, kami telah mengirimkan tautan reset kata sandi ke kotak masuk Anda.';
        $this->session->flash('success', $message);

        if ($request->wantsJson() && !$request->isInertia()) {
            return Response::json(['message' => $message]);
        }

        return Response::redirect('/forgot-password');
    }
}
