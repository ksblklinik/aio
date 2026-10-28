<?php

declare(strict_types=1);

namespace App\Modules\Seal\Controllers;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Seal\Contracts\DocumentSealRegistryInterface;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;
use Core\Inertia\Inertia;
use Throwable;

class SealController
{
    public function __construct(
        private AuthService $auth,
        private DocumentSealRegistryInterface $sealService,
        private Connection $db,
        private Session $session,
        private ?Inertia $inertia = null
    ) {}

    public function index(Request $request): Response
    {
        $statusFilter = $request->query('status') ? (string)$request->query('status') : null;
        $typeFilter = $request->query('document_type') ? (string)$request->query('document_type') : null;
        $searchFilter = $request->query('search') ? (string)$request->query('search') : null;

        $filters = array_filter([
            'status' => $statusFilter,
            'document_type' => $typeFilter,
            'search' => $searchFilter,
            'limit' => 100,
        ]);

        $seals = $this->sealService->listSeals($filters);

        $selectedToken = $request->query('token') ? (string)$request->query('token') : null;
        $selectedSeal = null;
        $verificationLogs = [];

        if ($selectedToken) {
            $selectedSeal = $this->sealService->getSealByToken($selectedToken);
            if ($selectedSeal) {
                $verificationLogs = $this->sealService->getVerificationLogs((int)$selectedSeal['id']);
            }
        } elseif (!empty($seals)) {
            $selectedSeal = $seals[0];
            $verificationLogs = $this->sealService->getVerificationLogs((int)$selectedSeal['id']);
        }

        if ($this->inertia) {
            return $this->inertia->render('Seal/Index', [
                'seals' => $seals,
                'selectedSeal' => $selectedSeal,
                'verificationLogs' => $verificationLogs,
                'filters' => [
                    'status' => $statusFilter,
                    'document_type' => $typeFilter,
                    'search' => $searchFilter,
                ],
                'flash' => [
                    'success' => $this->session->getFlash('success'),
                    'error' => $this->session->getFlash('error'),
                ],
            ]);
        }

        return Response::json([
            'seals' => $seals,
            'selectedSeal' => $selectedSeal,
            'verificationLogs' => $verificationLogs,
        ]);
    }

    public function store(Request $request): Response
    {
        try {
            $data = [
                'document_type' => (string) $request->input('document_type', 'custom'),
                'document_number' => (string) $request->input('document_number'),
                'title' => (string) $request->input('title'),
                'issuer_name' => (string) $request->input('issuer_name', 'Institusi Resmi'),
                'signer_name' => (string) $request->input('signer_name'),
                'signer_role' => $request->input('signer_role') ? (string) $request->input('signer_role') : null,
                'signer_credential' => $request->input('signer_credential') ? (string) $request->input('signer_credential') : null,
                'subject_name' => (string) $request->input('subject_name'),
                'subject_identifier' => $request->input('subject_identifier') ? (string) $request->input('subject_identifier') : null,
                'valid_from' => $request->input('valid_from') ? (string) $request->input('valid_from') : null,
                'valid_until' => $request->input('valid_until') ? (string) $request->input('valid_until') : null,
                'payload' => [
                    'document_number' => (string) $request->input('document_number'),
                    'title' => (string) $request->input('title'),
                    'signer_name' => (string) $request->input('signer_name'),
                    'subject_name' => (string) $request->input('subject_name'),
                    'notes' => $request->input('notes') ? (string) $request->input('notes') : null,
                ],
            ];

            $newSeal = $this->sealService->sealDocument($data);
            $this->session->flash('success', "Segel dokumen {$newSeal['document_number']} berhasil diterbitkan.");
            return Response::redirect('/seal?token=' . $newSeal['verification_token']);
        } catch (Throwable $e) {
            $this->session->flash('error', 'Gagal menerbitkan segel: ' . $e->getMessage());
            return Response::redirect('/seal');
        }
    }

    public function revoke(Request $request, array $params = []): Response
    {
        $token = (string) ($params['token'] ?? $request->input('token', ''));
        $reason = (string) $request->input('reason', 'Dibatalkan oleh administrator');

        try {
            $this->sealService->revokeSeal($token, $reason);
            $this->session->flash('success', 'Segel dokumen berhasil dicabut (revoked).');
        } catch (Throwable $e) {
            $this->session->flash('error', 'Gagal mencabut dokumen: ' . $e->getMessage());
        }

        return Response::redirect('/seal?token=' . $token);
    }
}
