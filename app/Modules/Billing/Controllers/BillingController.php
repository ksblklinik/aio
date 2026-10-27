<?php

declare(strict_types=1);

namespace App\Modules\Billing\Controllers;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Billing\Contracts\BillingRegistryInterface;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;
use Core\Inertia\Inertia;
use Throwable;

class BillingController
{
    public function __construct(
        private AuthService $auth,
        private BillingRegistryInterface $billingService,
        private Session $session,
        private ?Inertia $inertia = null
    ) {}

    public function index(Request $request): Response
    {
        $tenant = $this->auth->currentTenant();

        $filters = [
            'type' => $request->query('type') ? (string) $request->query('type') : null,
            'status' => $request->query('status') ? (string) $request->query('status') : null,
            'payment_status' => $request->query('payment_status') ? (string) $request->query('payment_status') : null,
            'search' => $request->query('q') ? (string) $request->query('q') : null,
        ];

        $invoices = $this->billingService->listInvoices(array_filter($filters, fn($v) => $v !== null), 50);
        $summary = $this->billingService->getBillingSummary();

        if ($this->inertia) {
            return $this->inertia->render('Billing/Index', [
                'invoices' => $invoices,
                'summary' => $summary,
                'filters' => $filters,
                'currentTenant' => $tenant ? [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                ] : null,
            ]);
        }

        return Response::json([
            'status' => 'success',
            'summary' => $summary,
            'invoices' => $invoices,
        ]);
    }

    public function show(int $id): Response
    {
        $invoice = $this->billingService->getInvoice($id);
        if (!$invoice) {
            return Response::json(['status' => 'error', 'message' => 'Invoice tidak ditemukan.'], 404);
        }

        if ($this->inertia) {
            return $this->inertia->render('Billing/Show', [
                'invoice' => $invoice,
            ]);
        }

        return Response::json([
            'status' => 'success',
            'data' => $invoice,
        ]);
    }

    public function store(Request $request): Response
    {
        try {
            $user = $this->auth->user();

            $data = [
                'type' => $request->input('type', 'invoice'),
                'contact_id' => $request->input('contact_id'),
                'contact_name' => $request->input('contact_name'),
                'status' => $request->input('status', 'draft'),
                'issue_date' => $request->input('issue_date', date('Y-m-d')),
                'due_date' => $request->input('due_date'),
                'notes' => $request->input('notes'),
                'created_by' => $user ? $user->id : null,
                'items' => (array) $request->input('items', []),
            ];

            $invoice = $this->billingService->createInvoice($data);
            $this->session->flash('success', "Invoice {$invoice['invoice_number']} berhasil dibuat.");

            if ($request->wantsJson()) {
                return Response::json(['status' => 'success', 'data' => $invoice], 201);
            }

            return Response::redirect('/billing');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());

            if ($request->wantsJson()) {
                return Response::json(['status' => 'error', 'message' => $e->getMessage()], 422);
            }

            return Response::redirect('/billing');
        }
    }

    public function issue(int $id, Request $request): Response
    {
        try {
            $deductInventory = (bool) $request->input('deduct_inventory', true);
            $invoice = $this->billingService->issueInvoice($id, $deductInventory);

            $this->session->flash('success', "Invoice {$invoice['invoice_number']} berhasil diterbitkan.");

            if ($request->wantsJson()) {
                return Response::json(['status' => 'success', 'data' => $invoice]);
            }

            return Response::redirect('/billing');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());

            if ($request->wantsJson()) {
                return Response::json(['status' => 'error', 'message' => $e->getMessage()], 422);
            }

            return Response::redirect('/billing');
        }
    }

    public function recordPayment(int $id, Request $request): Response
    {
        try {
            $user = $this->auth->user();

            $paymentData = [
                'amount' => (float) $request->input('amount', 0),
                'account_id' => $request->input('account_id'),
                'payment_method' => $request->input('payment_method', 'cash'),
                'payment_date' => $request->input('payment_date', date('Y-m-d')),
                'reference_note' => $request->input('reference_note'),
                'created_by' => $user ? $user->id : null,
            ];

            $invoice = $this->billingService->recordPayment($id, $paymentData);
            $this->session->flash('success', "Pembayaran untuk {$invoice['invoice_number']} berhasil dicatat.");

            if ($request->wantsJson()) {
                return Response::json(['status' => 'success', 'data' => $invoice]);
            }

            return Response::redirect('/billing');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());

            if ($request->wantsJson()) {
                return Response::json(['status' => 'error', 'message' => $e->getMessage()], 422);
            }

            return Response::redirect('/billing');
        }
    }

    public function quickCheckout(Request $request): Response
    {
        try {
            $user = $this->auth->user();

            $data = [
                'type' => 'invoice',
                'contact_id' => $request->input('contact_id'),
                'contact_name' => $request->input('contact_name'),
                'account_id' => $request->input('account_id'),
                'payment_amount' => (float) $request->input('payment_amount', 0),
                'payment_method' => $request->input('payment_method', 'cash'),
                'created_by' => $user ? $user->id : null,
                'items' => (array) $request->input('items', []),
            ];

            $result = $this->billingService->quickCheckout($data);
            $this->session->flash('success', "Kasir berhasil! Invoice {$result['invoice_number']} telah lunas.");

            if ($request->wantsJson()) {
                return Response::json(['status' => 'success', 'data' => $result], 201);
            }

            return Response::redirect('/billing');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());

            if ($request->wantsJson()) {
                return Response::json(['status' => 'error', 'message' => $e->getMessage()], 422);
            }

            return Response::redirect('/billing');
        }
    }

    public function cancel(int $id, Request $request): Response
    {
        try {
            $reason = (string) $request->input('reason', 'Pembatalan transaksi');
            $revertStock = (bool) $request->input('revert_stock', true);

            $invoice = $this->billingService->cancelInvoice($id, $reason, $revertStock);
            $this->session->flash('success', "Invoice {$invoice['invoice_number']} berhasil dibatalkan.");

            if ($request->wantsJson()) {
                return Response::json(['status' => 'success', 'data' => $invoice]);
            }

            return Response::redirect('/billing');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());

            if ($request->wantsJson()) {
                return Response::json(['status' => 'error', 'message' => $e->getMessage()], 422);
            }

            return Response::redirect('/billing');
        }
    }

    public function receipt(int $id): Response
    {
        try {
            $receiptData = $this->billingService->generateReceipt($id);

            // Render printable thermal receipt view
            $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Struk Pembayaran - {$receiptData['invoice_number']}</title>
    <style>
        body { font-family: monospace; width: 300px; margin: 0 auto; padding: 10px; font-size: 12px; }
        .header { text-align: center; border-bottom: 1px dashed #000; padding-bottom: 8px; margin-bottom: 8px; }
        .title { font-weight: bold; font-size: 14px; }
        .row { display: flex; justify-content: space-between; margin: 2px 0; }
        .divider { border-bottom: 1px dashed #000; margin: 6px 0; }
        .footer { text-align: center; margin-top: 10px; font-size: 10px; }
        @media print { body { width: 100%; } }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">BUKTI TRANSAKSI PEMBAYARAN</div>
        <div>No: {$receiptData['invoice_number']}</div>
        <div>Tgl: {$receiptData['issue_date']}</div>
        <div>Klien: {$receiptData['contact_name']}</div>
    </div>
    <div class="items">
HTML;
            foreach ($receiptData['items'] as $item) {
                $html .= sprintf(
                    '<div class="row"><span>%s x %.2f</span><span>Rp %s</span></div>',
                    htmlspecialchars($item['name']),
                    $item['qty'],
                    number_format($item['subtotal'], 0, ',', '.')
                );
            }

            $html .= sprintf(
                '<div class="divider"></div>' .
                '<div class="row"><span>Subtotal:</span><span>Rp %s</span></div>' .
                '<div class="row"><span>Diskon:</span><span>Rp %s</span></div>' .
                '<div class="row"><span>Pajak:</span><span>Rp %s</span></div>' .
                '<div class="row" style="font-weight:bold;"><span>GRAND TOTAL:</span><span>Rp %s</span></div>' .
                '<div class="row"><span>Dibayar:</span><span>Rp %s</span></div>' .
                '<div class="row"><span>Sisa Tagihan:</span><span>Rp %s</span></div>' .
                '<div class="divider"></div>' .
                '<div class="footer">Terima kasih atas kunjungan & transaksi Anda.<br>Dicetak pada: %s</div>' .
                '</body></html>',
                number_format($receiptData['subtotal'], 0, ',', '.'),
                number_format($receiptData['discount_total'], 0, ',', '.'),
                number_format($receiptData['tax_total'], 0, ',', '.'),
                number_format($receiptData['grand_total'], 0, ',', '.'),
                number_format($receiptData['amount_paid'], 0, ',', '.'),
                number_format($receiptData['amount_due'], 0, ',', '.'),
                $receiptData['printed_at']
            );

            return Response::html($html);
        } catch (Throwable $e) {
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], 404);
        }
    }
}
