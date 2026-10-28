<?php

declare(strict_types=1);

namespace App\Modules\Tariff\Controllers;

use App\Modules\Tariff\Contracts\TariffRegistryInterface;
use Core\Http\Request;
use Core\Http\Response;
use Throwable;

class ApiTariffController
{
    public function __construct(
        private TariffRegistryInterface $tariffService
    ) {}

    public function priceBooks(Request $request): Response
    {
        $filters = [];
        if ($request->query('is_active') !== null) {
            $filters['is_active'] = (int) $request->query('is_active');
        }
        if ($request->query('payer_contact_id')) {
            $filters['payer_contact_id'] = (int) $request->query('payer_contact_id');
        }
        if ($request->query('search')) {
            $filters['search'] = (string) $request->query('search');
        }

        return Response::json([
            'status' => 'success',
            'data' => $this->tariffService->listPriceBooks($filters),
        ]);
    }

    public function storePriceBook(Request $request): Response
    {
        try {
            $data = [
                'code' => (string) $request->input('code'),
                'name' => (string) $request->input('name'),
                'description' => $request->input('description') ? (string) $request->input('description') : null,
                'payer_contact_id' => $request->input('payer_contact_id') ? (int) $request->input('payer_contact_id') : null,
                'currency' => (string) $request->input('currency', 'IDR'),
                'is_active' => (int) $request->input('is_active', 1),
                'is_default' => (int) $request->input('is_default', 0),
                'valid_from' => $request->input('valid_from') ? (string) $request->input('valid_from') : null,
                'valid_until' => $request->input('valid_until') ? (string) $request->input('valid_until') : null,
            ];

            $priceBook = $this->tariffService->createPriceBook($data);

            return Response::json([
                'status' => 'success',
                'message' => 'Buku tarif berhasil dibuat.',
                'data' => $priceBook,
            ], 201);
        } catch (Throwable $e) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function rates(Request $request): Response
    {
        $priceBookId = (int) $request->query('price_book_id');
        if (!$priceBookId) {
            return Response::json([
                'status' => 'error',
                'message' => 'Parameter price_book_id wajib disertakan.',
            ], 400);
        }

        $filters = [];
        if ($request->query('room_type')) {
            $filters['room_type'] = (string) $request->query('room_type');
        }
        if ($request->query('search')) {
            $filters['search'] = (string) $request->query('search');
        }

        return Response::json([
            'status' => 'success',
            'data' => $this->tariffService->listRates($priceBookId, $filters),
        ]);
    }

    public function storeRate(Request $request): Response
    {
        try {
            $priceBookId = (int) $request->input('price_book_id');
            $itemId = (int) $request->input('item_id');
            $baseRate = (float) $request->input('base_rate');

            $options = [
                'room_type' => $request->input('room_type') ? (string) $request->input('room_type') : null,
                'min_qty' => (float) $request->input('min_qty', 1.0),
                'coverage_type' => (string) $request->input('coverage_type', 'fixed'),
                'coverage_amount' => $request->input('coverage_amount') !== null ? (float) $request->input('coverage_amount') : null,
                'patient_copay' => (float) $request->input('patient_copay', 0.0),
            ];

            $rate = $this->tariffService->setRate($priceBookId, $itemId, $baseRate, $options);

            return Response::json([
                'status' => 'success',
                'message' => 'Tarif berhasil disimpan.',
                'data' => $rate,
            ]);
        } catch (Throwable $e) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function resolvePrice(Request $request): Response
    {
        try {
            $itemId = (int) $request->input('item_id');
            $priceBookId = $request->input('price_book_id') ? (int) $request->input('price_book_id') : null;
            $qty = (float) $request->input('qty', 1.0);
            $options = [];
            if ($request->input('room_type')) {
                $options['room_type'] = (string) $request->input('room_type');
            }

            $result = $this->tariffService->resolvePrice($itemId, $priceBookId, $qty, $options);

            return Response::json([
                'status' => 'success',
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function calculateSplits(Request $request): Response
    {
        try {
            $itemId = (int) $request->input('item_id');
            $actualAmount = (float) $request->input('actual_amount');
            $priceBookId = $request->input('price_book_id') ? (int) $request->input('price_book_id') : null;
            $performerContactId = $request->input('performer_contact_id') ? (int) $request->input('performer_contact_id') : null;

            $splits = $this->tariffService->calculateSplits($itemId, $actualAmount, $priceBookId, $performerContactId);

            return Response::json([
                'status' => 'success',
                'data' => $splits,
            ]);
        } catch (Throwable $e) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function allocations(Request $request): Response
    {
        $filters = [];
        if ($request->query('invoice_id')) {
            $filters['invoice_id'] = (int) $request->query('invoice_id');
        }
        if ($request->query('beneficiary_contact_id')) {
            $filters['beneficiary_contact_id'] = (int) $request->query('beneficiary_contact_id');
        }
        if ($request->query('status')) {
            $filters['status'] = (string) $request->query('status');
        }

        return Response::json([
            'status' => 'success',
            'data' => $this->tariffService->listAllocations($filters),
        ]);
    }

    public function beneficiarySummary(Request $request): Response
    {
        $beneficiaryContactId = (int) $request->query('beneficiary_contact_id');
        if (!$beneficiaryContactId) {
            return Response::json([
                'status' => 'error',
                'message' => 'Parameter beneficiary_contact_id wajib diisi.',
            ], 400);
        }

        return Response::json([
            'status' => 'success',
            'data' => $this->tariffService->getBeneficiarySummary($beneficiaryContactId),
        ]);
    }

    public function settle(Request $request): Response
    {
        try {
            $beneficiaryContactId = (int) $request->input('beneficiary_contact_id');
            $allocationIds = (array) $request->input('allocation_ids', []);
            $reference = $request->input('settlement_reference') ? (string) $request->input('settlement_reference') : null;
            $paymentAccount = $request->input('payment_account') ? (string) $request->input('payment_account') : null;

            $settlement = $this->tariffService->settleBeneficiaryFees($beneficiaryContactId, $allocationIds, $reference, $paymentAccount);

            return Response::json([
                'status' => 'success',
                'message' => 'Settlement fee berhasil diselesaikan.',
                'data' => $settlement,
            ]);
        } catch (Throwable $e) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
