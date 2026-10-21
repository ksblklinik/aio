<?php

declare(strict_types=1);

namespace Core\Middleware;

use Core\Auth\ApiTokenService;
use Core\Database\TenantContext;
use Core\Http\Request;
use Core\Http\Response;

class AuthenticateApiToken
{
    public function __construct(
        private ApiTokenService $tokenService,
        private TenantContext $tenantContext
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $authHeader = $request->header('authorization') ?? $request->header('http-authorization');
        $token = null;

        if ($authHeader && preg_match('/Bearer\s+(aio_pat_[a-zA-Z0-9_-]+)/i', $authHeader, $matches)) {
            $token = $matches[1];
        } elseif ($request->query('api_token')) {
            $token = (string) $request->query('api_token');
        }

        if (!$token) {
            return Response::json([
                'error' => 'Unauthorized',
                'message' => 'Header Authorization Bearer token wajib disertakan untuk mengakses REST API.',
            ], 401);
        }

        $tokenRecord = $this->tokenService->validateToken($token);

        if (!$tokenRecord) {
            return Response::json([
                'error' => 'Unauthorized',
                'message' => 'Token API tidak valid, telah dicabut, atau telah kedaluwarsa.',
            ], 401);
        }

        // Terapkan isolasi tenancy untuk request API ini
        $this->tenantContext->setTenantId((string) $tokenRecord['tenant_id']);

        // Simpan metadata token & user terautentikasi ke dalam atribut request
        $request->setAttribute('api_token', $tokenRecord);
        $request->setAttribute('api_user_id', (int) $tokenRecord['user_id']);
        $request->setAttribute('api_tenant_id', (int) $tokenRecord['tenant_id']);

        return $next($request);
    }
}
