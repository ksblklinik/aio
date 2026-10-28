<?php

declare(strict_types=1);

namespace App\Modules\Seal\Controllers;

use App\Modules\Seal\Contracts\DocumentSealRegistryInterface;
use Core\Http\Request;
use Core\Http\Response;
use Core\Inertia\Inertia;

class PublicVerifyController
{
    public function __construct(
        private DocumentSealRegistryInterface $sealService,
        private ?Inertia $inertia = null
    ) {}

    public function verify(Request $request, array $params = []): Response
    {
        $token = (string) ($params['token'] ?? $request->query('token', ''));

        $requestMeta = [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'referer' => $request->header('referer'),
        ];

        $verification = $this->sealService->verifyPublicDocument($token, $requestMeta);

        if ($this->inertia) {
            return $this->inertia->render('Seal/Verify', [
                'token' => $token,
                'verification' => $verification,
            ]);
        }

        $statusCode = $verification['status'] === 'not_found' ? 404 : 200;
        return Response::json($verification, $statusCode);
    }

    public function apiVerify(Request $request, array $params = []): Response
    {
        $token = (string) ($params['token'] ?? $request->query('token', ''));

        $requestMeta = [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'referer' => $request->header('referer'),
        ];

        $verification = $this->sealService->verifyPublicDocument($token, $requestMeta);
        $statusCode = $verification['status'] === 'not_found' ? 404 : 200;

        return Response::json($verification, $statusCode);
    }
}
