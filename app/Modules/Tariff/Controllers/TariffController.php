<?php

declare(strict_types=1);

namespace App\Modules\Tariff\Controllers;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Tariff\Contracts\TariffRegistryInterface;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;
use Core\Inertia\Inertia;
use PDO;
use Throwable;

class TariffController
{
    public function __construct(
        private AuthService $auth,
        private TariffRegistryInterface $tariffService,
        private Connection $db,
        private Session $session,
        private ?Inertia $inertia = null
    ) {}

    public function index(Request $request): Response
    {
        $tenant = $this->auth->currentTenant();
        $tenantId = $tenant ? (int)$tenant->id : 1;

        $priceBooks = $this->tariffService->listPriceBooks();
        $selectedBookId = $request->query('price_book_id') ? (int)$request->query('price_book_id') : null;

        if ($selectedBookId === null && !empty($priceBooks)) {
            $defaultBook = $this->tariffService->getDefaultPriceBook();
            $selectedBookId = $defaultBook ? (int)$defaultBook['id'] : (int)$priceBooks[0]['id'];
        }

        $rates = $selectedBookId ? $this->tariffService->listRates($selectedBookId) : [];
        $splitRules = $this->tariffService->listSplitRules();
        $allocations = $this->tariffService->listAllocations();

        // Ambil daftar items via registry
        $items = function_exists('items') ? items()->searchItems('', limit: 100) : [];

        // Ambil daftar contacts via registry
        $contactsList = function_exists('contacts') ? contacts()->search('', limit: 100) : [];
        $contacts = array_map(fn($c) => [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'type' => $c->type,
        ], $contactsList);

        if ($this->inertia) {
            return $this->inertia->render('Tariff/Index', [
                'priceBooks'     => $priceBooks,
                'selectedBookId' => $selectedBookId,
                'rates'          => $rates,
                'splitRules'     => $splitRules,
                'allocations'    => $allocations,
                'items'          => $items,
                'contacts'       => $contacts,
                'currentTenant'  => $tenant ? [
                    'id'   => $tenant->id,
                    'name' => $tenant->name,
                ] : null,
            ]);
        }

        return Response::json([
            'status'         => 'success',
            'priceBooks'     => $priceBooks,
            'selectedBookId' => $selectedBookId,
            'rates'          => $rates,
            'splitRules'     => $splitRules,
            'allocations'    => $allocations,
        ]);
    }

    public function storePriceBook(Request $request): Response
    {
        try {
            $this->tariffService->createPriceBook([
                'code' => (string) $request->input('code'),
                'name' => (string) $request->input('name'),
                'description' => $request->input('description') ? (string) $request->input('description') : null,
                'payer_contact_id' => $request->input('payer_contact_id') ? (int) $request->input('payer_contact_id') : null,
                'currency' => (string) $request->input('currency', 'IDR'),
                'is_active' => (int) $request->input('is_active', 1),
                'is_default' => (int) $request->input('is_default', 0),
            ]);

            $this->session->flash('success', 'Buku tarif berhasil ditambahkan.');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return Response::redirect('/tariff');
    }

    public function updatePriceBook(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        try {
            $this->tariffService->updatePriceBook($id, [
                'code' => (string) $request->input('code'),
                'name' => (string) $request->input('name'),
                'description' => $request->input('description') ? (string) $request->input('description') : null,
                'payer_contact_id' => $request->input('payer_contact_id') ? (int) $request->input('payer_contact_id') : null,
                'currency' => (string) $request->input('currency', 'IDR'),
                'is_active' => (int) $request->input('is_active', 1),
                'is_default' => (int) $request->input('is_default', 0),
            ]);

            $this->session->flash('success', 'Buku tarif berhasil diperbarui.');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return Response::redirect('/tariff?price_book_id=' . $id);
    }

    public function deletePriceBook(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        try {
            $this->tariffService->deletePriceBook($id);
            $this->session->flash('success', 'Buku tarif berhasil dihapus.');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return Response::redirect('/tariff');
    }

    public function storeRate(Request $request): Response
    {
        $priceBookId = (int) $request->input('price_book_id');
        try {
            $this->tariffService->setRate(
                $priceBookId,
                (int) $request->input('item_id'),
                (float) $request->input('base_rate'),
                [
                    'room_type' => $request->input('room_type') ? (string) $request->input('room_type') : null,
                    'min_qty' => (float) $request->input('min_qty', 1.0),
                    'coverage_type' => (string) $request->input('coverage_type', 'fixed'),
                    'coverage_amount' => $request->input('coverage_amount') !== null && $request->input('coverage_amount') !== '' ? (float) $request->input('coverage_amount') : null,
                    'patient_copay' => (float) $request->input('patient_copay', 0.0),
                ]
            );

            $this->session->flash('success', 'Tarif item berhasil disimpan.');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return Response::redirect('/tariff?price_book_id=' . $priceBookId);
    }

    public function deleteRate(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $priceBookId = (int) $request->input('price_book_id');
        try {
            $this->tariffService->deleteRate($id);
            $this->session->flash('success', 'Tarif berhasil dihapus.');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return Response::redirect('/tariff?price_book_id=' . $priceBookId);
    }

    public function storeSplitRule(Request $request): Response
    {
        try {
            $this->tariffService->createSplitRule([
                'name' => (string) $request->input('name'),
                'recipient_type' => (string) $request->input('recipient_type'),
                'split_type' => (string) $request->input('split_type', 'percentage'),
                'split_value' => (float) $request->input('split_value'),
                'item_id' => $request->input('item_id') ? (int) $request->input('item_id') : null,
                'price_book_id' => $request->input('price_book_id') ? (int) $request->input('price_book_id') : null,
                'default_beneficiary_contact_id' => $request->input('default_beneficiary_contact_id') ? (int) $request->input('default_beneficiary_contact_id') : null,
                'target_account_code' => $request->input('target_account_code') ? (string) $request->input('target_account_code') : null,
            ]);

            $this->session->flash('success', 'Aturan bagi hasil berhasil ditambahkan.');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return Response::redirect('/tariff');
    }

    public function deleteSplitRule(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        try {
            $this->tariffService->deleteSplitRule($id);
            $this->session->flash('success', 'Aturan bagi hasil berhasil dihapus.');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return Response::redirect('/tariff');
    }

    public function settleFees(Request $request): Response
    {
        try {
            $beneficiaryContactId = (int) $request->input('beneficiary_contact_id');
            $allocationIds = (array) $request->input('allocation_ids', []);
            $reference = $request->input('settlement_reference') ? (string) $request->input('settlement_reference') : null;

            $result = $this->tariffService->settleBeneficiaryFees($beneficiaryContactId, $allocationIds, $reference);

            $this->session->flash('success', "Settlement sebesar Rp " . number_format($result['total_amount'], 0, ',', '.') . " berhasil diselesaikan ({$result['settlement_reference']}).");
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return Response::redirect('/tariff');
    }
}
