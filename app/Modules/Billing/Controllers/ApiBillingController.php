<?php

declare(strict_types=1);

namespace App\Modules\Billing\Controllers;

use App\Modules\Billing\Contracts\BillingRegistryInterface;
use Core\Http\Request;
use Core\Http\Response;
use Throwable;

class ApiBillingController
{
    public function __construct(
        private BillingRegistryInterface $billingService
    ) {}

    public function index(Request $request): Response
    {
        $filters = [
            'type' => $request->query('type') ? (string) $request->query('type') : null,
            'status' => $request->query('status') ? (string) $request->query('status') : null,
            'payment_status' => $request->query('payment_status') ? (string) $request->query('payment_status') : null,
            'search' => $request->query('q') ? (string) $request->query('q') : null,
            'contact_id' => $request->query('contact_id') ? (int) $request->query('contact_id') : null,
            'start_date' => $request->query('start_date') ? (string) $request->query('start_date') : null,
            'end_date' => $request->query('end_date') ? (string) $request->query('end_date') : null,
        ];

        $limit = (int) $request->query('limit', 50);
        $offset = (int) $request->query('offset', 0);

        $invoices = $this->billingService->listInvoices(array_filter($filters, fn($v) => $v !== null), $limit, $offset);

        return Response::json([
            'status' => 'success',
            'data' => $invoices,
        ]);
    }

    public function show(int $id): Response
    {
        $invoice = $this->billingService->getInvoice($id);
        if (!$invoice) {
            return Response::json(['status' => 'error', 'message' => 'Invoice not found.'], 404);
        }

        return Response::json([
            'status' => 'success',
            'data' => $invoice,
        ]);
    }

    public function store(Request $request): Response
    {
        try {
            $data = $request->json();
            if (empty($data)) {
                $data = $request->all();
            }

            $invoice = $this->billingService->createInvoice($data);

            return Response::json([
                'status' => 'success',
                'data' => $invoice,
            ], 201);
        } catch (Throwable $e) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function issue(int $id, Request $request): Response
    {
        try {
            $deductInventory = (bool) $request->input('deduct_inventory', true);
            $invoice = $this->billingService->issueInvoice($id, $deductInventory);

            return Response::json([
                'status' => 'success',
                'data' => $invoice,
            ]);
        } catch (Throwable $e) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function payment(int $id, Request $request): Response
    {
        try {
            $paymentData = $request->json();
            if (empty($paymentData)) {
                $paymentData = $request->all();
            }

            $invoice = $this->billingService->recordPayment($id, $paymentData);

            return Response::json([
                'status' => 'success',
                'data' => $invoice,
            ]);
        } catch (Throwable $e) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function quickCheckout(Request $request): Response
    {
        try {
            $data = $request->json();
            if (empty($data)) {
                $data = $request->all();
            }

            $result = $this->billingService->quickCheckout($data);

            return Response::json([
                'status' => 'success',
                'data' => $result,
            ], 201);
        } catch (Throwable $e) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function cancel(int $id, Request $request): Response
    {
        try {
            $reason = (string) $request->input('reason', 'Pembatalan transaksi');
            $revertStock = (bool) $request->input('revert_stock', true);

            $invoice = $this->billingService->cancelInvoice($id, $reason, $revertStock);

            return Response::json([
                'status' => 'success',
                'data' => $invoice,
            ]);
        } catch (Throwable $e) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function summary(): Response
    {
        $summary = $this->billingService->getBillingSummary();

        return Response::json([
            'status' => 'success',
            'data' => $summary,
        ]);
    }

    public function receipt(int $id): Response
    {
        try {
            $receipt = $this->billingService->generateReceipt($id);

            return Response::json([
                'status' => 'success',
                'data' => $receipt,
            ]);
        } catch (Throwable $e) {
            return Response::json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 404);
        }
    }
}
