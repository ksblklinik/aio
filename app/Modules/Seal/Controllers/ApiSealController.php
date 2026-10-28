<?php

declare(strict_types=1);

namespace App\Modules\Seal\Controllers;

use App\Modules\Seal\Contracts\DocumentSealRegistryInterface;
use Core\Http\Request;
use Core\Http\Response;
use Throwable;

class ApiSealController
{
    public function __construct(
        private DocumentSealRegistryInterface $sealService
    ) {}

    public function index(Request $request): Response
    {
        $filters = [];
        if ($request->query('document_type')) {
            $filters['document_type'] = (string) $request->query('document_type');
        }
        if ($request->query('status')) {
            $filters['status'] = (string) $request->query('status');
        }
        if ($request->query('search')) {
            $filters['search'] = (string) $request->query('search');
        }
        if ($request->query('limit')) {
            $filters['limit'] = (int) $request->query('limit');
        }
        if ($request->query('offset')) {
            $filters['offset'] = (int) $request->query('offset');
        }

        return Response::json([
            'status' => 'success',
            'data' => $this->sealService->listSeals($filters),
        ]);
    }

    public function store(Request $request): Response
    {
        try {
            $data = [
                'document_type' => (string) $request->input('document_type', 'custom'),
                'document_number' => (string) $request->input('document_number'),
                'title' => (string) $request->input('title'),
                'issuer_name' => $request->input('issuer_name') ? (string) $request->input('issuer_name') : null,
                'signer_name' => (string) $request->input('signer_name'),
                'signer_role' => $request->input('signer_role') ? (string) $request->input('signer_role') : null,
                'signer_credential' => $request->input('signer_credential') ? (string) $request->input('signer_credential') : null,
                'subject_name' => (string) $request->input('subject_name'),
                'subject_identifier' => $request->input('subject_identifier') ? (string) $request->input('subject_identifier') : null,
                'subject_masked_name' => $request->input('subject_masked_name') ? (string) $request->input('subject_masked_name') : null,
                'payload' => (array) $request->input('payload', []),
                'valid_from' => $request->input('valid_from') ? (string) $request->input('valid_from') : null,
                'valid_until' => $request->input('valid_until') ? (string) $request->input('valid_until') : null,
                'metadata' => (array) $request->input('metadata', []),
            ];

            $seal = $this->sealService->sealDocument($data);

            return Response::json([
                'status' => 'success',
                'message' => 'Segel kriptografis dokumen berhasil diterbitkan.',
                'data' => $seal,
            ], 201);
        } catch (Throwable $e) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function show(Request $request, array $params = []): Response
    {
        $token = (string) ($params['token'] ?? $request->query('token', ''));
        $seal = $this->sealService->getSealByToken($token);

        if (!$seal) {
            return Response::json([
                'status' => 'not_found',
                'message' => 'Dokumen tidak ditemukan.',
                'data' => null,
            ], 404);
        }

        return Response::json([
            'status' => 'success',
            'data' => $seal,
        ]);
    }

    public function revoke(Request $request, array $params = []): Response
    {
        try {
            $token = (string) ($params['token'] ?? $request->input('token', ''));
            $reason = (string) $request->input('reason', '');

            $revoked = $this->sealService->revokeSeal($token, $reason);

            return Response::json([
                'status' => 'success',
                'message' => 'Dokumen berhasil dicabut (revoked).',
                'data' => $revoked,
            ]);
        } catch (Throwable $e) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function verifyPayload(Request $request): Response
    {
        try {
            $token = (string) $request->input('token', '');
            $payload = (array) $request->input('payload', []);

            $isMatch = $this->sealService->verifyPayloadIntegrity($token, $payload);

            return Response::json([
                'status' => 'success',
                'token' => $token,
                'is_integrity_valid' => $isMatch,
                'message' => $isMatch ? 'Payload valid dan cocok dengan hash kanonikal.' : 'Payload tidak cocok atau dokumen telah dimodifikasi.',
            ]);
        } catch (Throwable $e) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
