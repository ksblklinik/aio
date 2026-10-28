<?php

declare(strict_types=1);

namespace App\Modules\Terminology\Controllers;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Terminology\Contracts\TerminologyRegistryInterface;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;
use Core\Inertia\Inertia;
use Throwable;

class TerminologyController
{
    public function __construct(
        private AuthService $auth,
        private TerminologyRegistryInterface $terminologyService,
        private Connection $db,
        private Session $session,
        private ?Inertia $inertia = null
    ) {}

    public function index(Request $request): Response
    {
        $codeSystems = $this->terminologyService->listCodeSystems();
        $selectedSystemId = $request->query('code_system_id') ? (int)$request->query('code_system_id') : null;

        if ($selectedSystemId === null && !empty($codeSystems)) {
            $selectedSystemId = (int)$codeSystems[0]['id'];
        }

        $searchQuery = $request->query('search') ? (string)$request->query('search') : null;
        $concepts = [];
        if ($selectedSystemId) {
            $concepts = $this->terminologyService->listConcepts($selectedSystemId, [
                'search' => $searchQuery,
                'limit' => 50,
            ]);
        }

        $mappings = $this->terminologyService->listMappings();

        if ($this->inertia) {
            return $this->inertia->render('Terminology/Index', [
                'codeSystems'      => $codeSystems,
                'selectedSystemId' => $selectedSystemId,
                'concepts'         => $concepts,
                'mappings'         => $mappings,
                'searchQuery'      => $searchQuery,
                'flash' => [
                    'success' => $this->session->getFlash('success'),
                    'error'   => $this->session->getFlash('error'),
                ],
            ]);
        }

        return Response::json([
            'codeSystems' => $codeSystems,
            'selectedSystemId' => $selectedSystemId,
            'concepts' => $concepts,
            'mappings' => $mappings,
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
                'is_system' => 0, // custom system created by tenant
            ];

            $this->terminologyService->createCodeSystem($data);
            $this->session->flash('success', 'Sistem kodifikasi berhasil ditambahkan.');
        } catch (Throwable $e) {
            $this->session->flash('error', 'Gagal menambahkan sistem kodifikasi: ' . $e->getMessage());
        }

        return Response::redirect('/terminology');
    }

    public function updateCodeSystem(Request $request, array $params = []): Response
    {
        try {
            $id = (int) ($params['id'] ?? $request->input('id'));
            $data = [
                'name' => (string) $request->input('name'),
                'uri' => $request->input('uri') ? (string) $request->input('uri') : null,
                'version' => $request->input('version') ? (string) $request->input('version') : null,
                'publisher' => $request->input('publisher') ? (string) $request->input('publisher') : null,
                'description' => $request->input('description') ? (string) $request->input('description') : null,
                'is_active' => (int) $request->input('is_active', 1),
            ];

            $this->terminologyService->updateCodeSystem($id, $data);
            $this->session->flash('success', 'Sistem kodifikasi berhasil diperbarui.');
        } catch (Throwable $e) {
            $this->session->flash('error', 'Gagal memperbarui sistem kodifikasi: ' . $e->getMessage());
        }

        return Response::redirect('/terminology?code_system_id=' . ($id ?? ''));
    }

    public function deleteCodeSystem(Request $request, array $params = []): Response
    {
        try {
            $id = (int) ($params['id'] ?? $request->input('id'));
            $this->terminologyService->deleteCodeSystem($id);
            $this->session->flash('success', 'Sistem kodifikasi berhasil dihapus.');
        } catch (Throwable $e) {
            $this->session->flash('error', 'Gagal menghapus sistem kodifikasi: ' . $e->getMessage());
        }

        return Response::redirect('/terminology');
    }

    public function storeConcept(Request $request): Response
    {
        $codeSystemId = (int) $request->input('code_system_id');
        try {
            $synonymsRaw = $request->input('synonyms');
            $synonyms = [];
            if (is_array($synonymsRaw)) {
                $synonyms = $synonymsRaw;
            } elseif (is_string($synonymsRaw) && trim($synonymsRaw) !== '') {
                $synonyms = array_map('trim', explode(',', $synonymsRaw));
            }

            $data = [
                'code_system_id' => $codeSystemId,
                'code' => (string) $request->input('code'),
                'display_name' => (string) $request->input('display_name'),
                'description' => $request->input('description') ? (string) $request->input('description') : null,
                'parent_code' => $request->input('parent_code') ? (string) $request->input('parent_code') : null,
                'synonyms' => $synonyms,
                'is_active' => (int) $request->input('is_active', 1),
            ];

            $this->terminologyService->createConcept($data);
            $this->session->flash('success', 'Konsep baru berhasil ditambahkan.');
        } catch (Throwable $e) {
            $this->session->flash('error', 'Gagal menambahkan konsep: ' . $e->getMessage());
        }

        return Response::redirect('/terminology?code_system_id=' . $codeSystemId);
    }

    public function deleteConcept(Request $request, array $params = []): Response
    {
        $codeSystemId = (int) $request->input('code_system_id');
        try {
            $id = (int) ($params['id'] ?? $request->input('id'));
            $this->terminologyService->deleteConcept($id);
            $this->session->flash('success', 'Konsep berhasil dihapus.');
        } catch (Throwable $e) {
            $this->session->flash('error', 'Gagal menghapus konsep: ' . $e->getMessage());
        }

        return Response::redirect('/terminology?code_system_id=' . $codeSystemId);
    }

    public function storeMapping(Request $request): Response
    {
        try {
            $data = [
                'source_system_id' => (int) $request->input('source_system_id'),
                'source_code' => (string) $request->input('source_code'),
                'target_system_id' => (int) $request->input('target_system_id'),
                'target_code' => (string) $request->input('target_code'),
                'equivalence' => (string) $request->input('equivalence', 'equivalent'),
                'comments' => $request->input('comments') ? (string) $request->input('comments') : null,
            ];

            $this->terminologyService->createMapping($data);
            $this->session->flash('success', 'Pemetaan kode lintas sistem berhasil disimpan.');
        } catch (Throwable $e) {
            $this->session->flash('error', 'Gagal menyimpan pemetaan: ' . $e->getMessage());
        }

        return Response::redirect('/terminology');
    }

    public function deleteMapping(Request $request, array $params = []): Response
    {
        try {
            $id = (int) ($params['id'] ?? $request->input('id'));
            $this->terminologyService->deleteMapping($id);
            $this->session->flash('success', 'Pemetaan kode berhasil dihapus.');
        } catch (Throwable $e) {
            $this->session->flash('error', 'Gagal menghapus pemetaan: ' . $e->getMessage());
        }

        return Response::redirect('/terminology');
    }

    public function bulkImport(Request $request): Response
    {
        $codeSystemId = (int) $request->input('code_system_id');
        try {
            $jsonContent = (string) $request->input('json_data');
            $decoded = json_decode($jsonContent, true);

            if (!is_array($decoded)) {
                throw new \InvalidArgumentException("Format JSON tidak valid atau bukan merupakan array objek konsep.");
            }

            $count = $this->terminologyService->bulkImportConcepts($codeSystemId, $decoded);
            $this->session->flash('success', "Berhasil mengimpor {$count} konsep terminologi.");
        } catch (Throwable $e) {
            $this->session->flash('error', 'Gagal melakukan bulk import: ' . $e->getMessage());
        }

        return Response::redirect('/terminology?code_system_id=' . $codeSystemId);
    }
}
