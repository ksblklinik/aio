<?php

declare(strict_types=1);

namespace App\Modules\Terminology\Controllers;

use App\Modules\Terminology\Contracts\TerminologyRegistryInterface;
use Core\Http\Request;
use Core\Http\Response;
use Throwable;

class ApiTerminologyController
{
    public function __construct(
        private TerminologyRegistryInterface $terminologyService
    ) {}

    public function search(Request $request): Response
    {
        $query = (string) $request->query('q', '');
        $system = $request->query('system') ? (string) $request->query('system') : null;
        $limit = $request->query('limit') ? (int) $request->query('limit') : 20;

        $results = $this->terminologyService->searchConcepts($query, $system, $limit);

        return Response::json([
            'status' => 'success',
            'query' => $query,
            'system' => $system,
            'count' => count($results),
            'data' => $results,
        ]);
    }

    public function codeSystems(Request $request): Response
    {
        $filters = [];
        if ($request->query('is_active') !== null) {
            $filters['is_active'] = (int) $request->query('is_active');
        }
        if ($request->query('search')) {
            $filters['search'] = (string) $request->query('search');
        }

        return Response::json([
            'status' => 'success',
            'data' => $this->terminologyService->listCodeSystems($filters),
        ]);
    }

    public function storeCodeSystem(Request $request): Response
    {
        try {
            $data = [
                'code' => (string) $request->input('code'),
                'name' => (string) $request->input('name'),
                'uri' => $request->input('uri') ? (string) $request->input('uri') : null,
                'version' => $request->input('version') ? (string) $request->input('version') : null,
                'publisher' => $request->input('publisher') ? (string) $request->input('publisher') : null,
                'description' => $request->input('description') ? (string) $request->input('description') : null,
                'is_active' => (int) $request->input('is_active', 1),
                'is_system' => (int) $request->input('is_system', 0),
            ];

            $codeSystem = $this->terminologyService->createCodeSystem($data);

            return Response::json([
                'status' => 'success',
                'message' => 'Sistem kodifikasi berhasil didaftarkan.',
                'data' => $codeSystem,
            ], 201);
        } catch (Throwable $e) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function concepts(Request $request): Response
    {
        $codeSystemId = (int) $request->query('code_system_id');
        if (!$codeSystemId) {
            return Response::json([
                'status' => 'error',
                'message' => 'Parameter code_system_id wajib diberikan.',
            ], 400);
        }

        $filters = [];
        if ($request->query('parent_code')) {
            $filters['parent_code'] = (string) $request->query('parent_code');
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
            'data' => $this->terminologyService->listConcepts($codeSystemId, $filters),
        ]);
    }

    public function storeConcept(Request $request): Response
    {
        try {
            $data = [
                'code_system_id' => $request->input('code_system_id') ? (int) $request->input('code_system_id') : null,
                'system_code' => $request->input('system_code') ? (string) $request->input('system_code') : null,
                'code' => (string) $request->input('code'),
                'display_name' => (string) $request->input('display_name'),
                'description' => $request->input('description') ? (string) $request->input('description') : null,
                'parent_code' => $request->input('parent_code') ? (string) $request->input('parent_code') : null,
                'synonyms' => $request->input('synonyms'),
                'properties' => $request->input('properties'),
                'is_active' => (int) $request->input('is_active', 1),
            ];

            $concept = $this->terminologyService->createConcept($data);

            return Response::json([
                'status' => 'success',
                'message' => 'Konsep terminologi berhasil ditambahkan.',
                'data' => $concept,
            ], 201);
        } catch (Throwable $e) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function mappings(Request $request): Response
    {
        $filters = [];
        if ($request->query('source_system_id')) {
            $filters['source_system_id'] = (int) $request->query('source_system_id');
        }
        if ($request->query('target_system_id')) {
            $filters['target_system_id'] = (int) $request->query('target_system_id');
        }
        if ($request->query('search')) {
            $filters['search'] = (string) $request->query('search');
        }

        return Response::json([
            'status' => 'success',
            'data' => $this->terminologyService->listMappings($filters),
        ]);
    }

    public function storeMapping(Request $request): Response
    {
        try {
            $data = [
                'source_system_id' => $request->input('source_system_id') ? (int) $request->input('source_system_id') : null,
                'source_system_code' => $request->input('source_system_code') ? (string) $request->input('source_system_code') : null,
                'source_code' => (string) $request->input('source_code'),
                'target_system_id' => $request->input('target_system_id') ? (int) $request->input('target_system_id') : null,
                'target_system_code' => $request->input('target_system_code') ? (string) $request->input('target_system_code') : null,
                'target_code' => (string) $request->input('target_code'),
                'equivalence' => (string) $request->input('equivalence', 'equivalent'),
                'comments' => $request->input('comments') ? (string) $request->input('comments') : null,
            ];

            $mapping = $this->terminologyService->createMapping($data);

            return Response::json([
                'status' => 'success',
                'message' => 'Pemetaan kode berhasil disimpan.',
                'data' => $mapping,
            ], 201);
        } catch (Throwable $e) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function resolveMapping(Request $request): Response
    {
        try {
            $sourceSystem = (string) $request->input('source_system_code');
            $sourceCode = (string) $request->input('source_code');
            $targetSystem = (string) $request->input('target_system_code');

            if (empty($sourceSystem) || empty($sourceCode) || empty($targetSystem)) {
                return Response::json([
                    'status' => 'error',
                    'message' => 'source_system_code, source_code, dan target_system_code wajib diisi.',
                ], 400);
            }

            $resolved = $this->terminologyService->resolveMapping($sourceSystem, $sourceCode, $targetSystem);

            if (!$resolved) {
                return Response::json([
                    'status' => 'not_found',
                    'message' => "Pemetaan dari {$sourceSystem}:{$sourceCode} ke {$targetSystem} tidak ditemukan.",
                    'data' => null,
                ], 404);
            }

            return Response::json([
                'status' => 'success',
                'data' => $resolved,
            ]);
        } catch (Throwable $e) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function bulkImport(Request $request): Response
    {
        try {
            $codeSystemId = (int) $request->input('code_system_id');
            $concepts = (array) $request->input('concepts', []);

            $count = $this->terminologyService->bulkImportConcepts($codeSystemId, $concepts);

            return Response::json([
                'status' => 'success',
                'message' => "Berhasil mengimpor {$count} konsep.",
                'count' => $count,
            ]);
        } catch (Throwable $e) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
