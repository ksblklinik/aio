<?php

declare(strict_types=1);

namespace App\Modules\Settings\Controllers;

use App\Modules\Auth\Services\AuthService;
use Core\Audit\AuditLogger;
use Core\Auth\ApiTokenService;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;
use Core\Inertia\Inertia;
use Core\Validation\ValidatesRequests;

class ApiTokenController
{
    use ValidatesRequests;

    public function __construct(
        private AuthService $auth,
        private ApiTokenService $tokenService,
        private AuditLogger $auditLogger,
        private Inertia $inertia,
        private Session $session,
        private ?\Core\Notification\NotificationService $notificationService = null
    ) {
    }

    public function index(Request $request): Response
    {
        $role = $this->auth->currentRole();
        if ($role !== 'owner' && $role !== 'admin' && !$this->auth->isSuperadmin()) {
            $this->session->flash('error', 'Hanya Owner atau Admin yang memiliki akses ke pengaturan API Tokens.');
            return Response::redirect('/dashboard');
        }

        $tenant = $this->auth->currentTenant();
        $tenantId = (int) ($tenant?->id ?? 0);

        $tokens = $this->tokenService->getTokensForTenant($tenantId);

        return $this->inertia->render('Settings/ApiTokens', [
            'tokens' => $tokens,
            'currentTenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ] : null,
            'newToken' => $this->session->getFlash('new_token'),
            'flash' => [
                'success' => $this->session->getFlash('success'),
                'error' => $this->session->getFlash('error'),
            ],
        ]);
    }

    public function store(Request $request): Response
    {
        $role = $this->auth->currentRole();
        if ($role !== 'owner' && $role !== 'admin' && !$this->auth->isSuperadmin()) {
            $this->session->flash('error', 'Akses ditolak.');
            return Response::redirect('/dashboard');
        }

        $validated = $this->validate($request, [
            'name' => 'required|string|max:100',
        ]);

        $tenant = $this->auth->currentTenant();
        $tenantId = (int) ($tenant?->id ?? 0);
        $user = $this->auth->user();
        $userId = (int) ($user?->id ?? 0);

        $abilitiesInput = $request->input('abilities');
        $abilities = ['*'];
        if (is_array($abilitiesInput) && !empty($abilitiesInput)) {
            $abilities = array_values(array_filter(array_map('strval', $abilitiesInput)));
        }

        $expiresInDays = $request->input('expires_in_days');
        $expiresAt = null;
        if (!empty($expiresInDays) && is_numeric($expiresInDays) && (int) $expiresInDays > 0) {
            $expiresAt = date('Y-m-d H:i:s', time() + ((int) $expiresInDays * 86400));
        }

        $created = $this->tokenService->createToken(
            $tenantId,
            $userId,
            $validated['name'],
            $abilities,
            $expiresAt
        );

        $this->auditLogger->log(
            action: 'api_token.created',
            description: "Membuat Personal Access Token baru: [{$validated['name']}]",
            entityType: 'api_token',
            entityId: (string) $created['id'],
            properties: ['name' => $validated['name'], 'abilities' => $abilities]
        );

        $this->notificationService?->send(
            $tenantId,
            $userId,
            'API Token Dibuat',
            "Personal Access Token [{$validated['name']}] berhasil diterbitkan untuk workspace ini.",
            'success',
            '/settings/api-tokens',
            'key'
        );

        $this->session->flash('new_token', $created['plain_token']);
        $this->session->flash('success', "API Token [{$validated['name']}] berhasil dibuat. Segera salin token Anda!");
        return Response::redirect('/settings/api-tokens');
    }

    public function destroy(Request $request, int $id): Response
    {
        $role = $this->auth->currentRole();
        if ($role !== 'owner' && $role !== 'admin' && !$this->auth->isSuperadmin()) {
            $this->session->flash('error', 'Akses ditolak.');
            return Response::redirect('/dashboard');
        }

        $tenant = $this->auth->currentTenant();
        $tenantId = (int) ($tenant?->id ?? 0);
        $user = $this->auth->user();
        $userId = (int) ($user?->id ?? 0);

        $this->tokenService->revokeToken($id, $tenantId);

        $this->auditLogger->log(
            action: 'api_token.revoked',
            description: "Mencabut API Token #{$id}",
            entityType: 'api_token',
            entityId: (string) $id
        );

        $this->notificationService?->send(
            $tenantId,
            $userId,
            'API Token Dicabut',
            "API Token #{$id} telah dicabut dan tidak dapat digunakan kembali.",
            'warning',
            '/settings/api-tokens',
            'key'
        );

        $this->session->flash('success', 'API Token berhasil dicabut.');
        return Response::redirect('/settings/api-tokens');
    }
}
