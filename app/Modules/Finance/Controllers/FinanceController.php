<?php

declare(strict_types=1);

namespace App\Modules\Finance\Controllers;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Finance\Contracts\FinanceRegistryInterface;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;
use Core\Inertia\Inertia;
use Throwable;

class FinanceController
{
    public function __construct(
        private AuthService $auth,
        private FinanceRegistryInterface $financeService,
        private Session $session,
        private ?Inertia $inertia = null
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = $this->auth->currentTenant();
        $accounts = $this->financeService->listAccounts();
        $categories = $this->financeService->listCategories();
        $transactions = $this->financeService->listTransactions([], limit: 30);

        $startDate = (string) $request->query('start_date', date('Y-m-01'));
        $endDate = (string) $request->query('end_date', date('Y-m-d'));
        $cashFlow = $this->financeService->getCashFlowStatement($startDate, $endDate);

        if ($this->inertia) {
            return $this->inertia->render('Finance/Index', [
                'accounts' => $accounts,
                'categories' => $categories,
                'recentTransactions' => $transactions,
                'cashFlow' => $cashFlow,
                'currentTenant' => $tenant ? [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                ] : null,
            ]);
        }

        return Response::json([
            'accounts' => $accounts,
            'categories' => $categories,
            'recent_transactions' => $transactions,
            'cash_flow' => $cashFlow,
        ]);
    }

    public function storeAccount(Request $request): Response
    {
        try {
            $id = $this->financeService->createAccount([
                'code' => $request->input('code'),
                'name' => $request->input('name'),
                'type' => $request->input('type', 'cash'),
                'account_number' => $request->input('account_number'),
                'bank_name' => $request->input('bank_name'),
                'initial_balance' => (float) $request->input('initial_balance', 0),
            ]);

            $this->session->flash('success', 'Akun keuangan berhasil ditambahkan.');
            return Response::redirect('/finance');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/finance');
        }
    }

    public function storeInflow(Request $request): Response
    {
        try {
            $accountId = (int) $request->input('account_id');
            $amount = (float) $request->input('amount');
            $categoryId = (int) $request->input('category_id');
            $contactId = $request->input('contact_id') ? (int) $request->input('contact_id') : null;
            $description = (string) $request->input('description', '');
            $referenceNumber = $request->input('reference_number') ? (string) $request->input('reference_number') : null;

            $this->financeService->recordInflow(
                accountId: $accountId,
                amount: $amount,
                categoryId: $categoryId,
                contactId: $contactId,
                description: $description,
                referenceNumber: $referenceNumber
            );

            $this->session->flash('success', 'Penerimaan dana berhasil dicatat.');
            return Response::redirect('/finance');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/finance');
        }
    }

    public function storeOutflow(Request $request): Response
    {
        try {
            $accountId = (int) $request->input('account_id');
            $amount = (float) $request->input('amount');
            $categoryId = (int) $request->input('category_id');
            $contactId = $request->input('contact_id') ? (int) $request->input('contact_id') : null;
            $description = (string) $request->input('description', '');
            $referenceNumber = $request->input('reference_number') ? (string) $request->input('reference_number') : null;
            $requiresApproval = (bool) $request->input('requires_approval', false);

            $this->financeService->recordOutflow(
                accountId: $accountId,
                amount: $amount,
                categoryId: $categoryId,
                contactId: $contactId,
                description: $description,
                referenceNumber: $referenceNumber,
                requiresApproval: $requiresApproval
            );

            $msg = $requiresApproval
                ? 'Pengajuan pengeluaran kas berhasil diajukan dan menunggu persetujuan.'
                : 'Pengeluaran kas berhasil dicatat.';
            $this->session->flash('success', $msg);
            return Response::redirect('/finance');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/finance');
        }
    }

    public function storeTransfer(Request $request): Response
    {
        try {
            $sourceAccountId = (int) $request->input('source_account_id');
            $targetAccountId = (int) $request->input('target_account_id');
            $amount = (float) $request->input('amount');
            $description = (string) $request->input('description', '');
            $referenceNumber = $request->input('reference_number') ? (string) $request->input('reference_number') : null;

            $this->financeService->transfer(
                sourceAccountId: $sourceAccountId,
                targetAccountId: $targetAccountId,
                amount: $amount,
                description: $description,
                referenceNumber: $referenceNumber
            );

            $this->session->flash('success', 'Transfer antar kas/bank berhasil dieksekusi.');
            return Response::redirect('/finance');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/finance');
        }
    }

    public function approve(int $id): Response
    {
        try {
            $user = $this->auth->user();
            $userId = $user ? (int) $user->id : 1;
            $this->financeService->approveOutflow($id, $userId);

            $this->session->flash('success', 'Pengeluaran kas berhasil disetujui.');
            return Response::redirect('/finance');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/finance');
        }
    }

    public function reject(Request $request, int $id): Response
    {
        try {
            $user = $this->auth->user();
            $userId = $user ? (int) $user->id : 1;
            $reason = (string) $request->input('reason', '');
            $this->financeService->rejectOutflow($id, $userId, $reason);

            $this->session->flash('success', 'Pengeluaran kas telah ditolak.');
            return Response::redirect('/finance');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/finance');
        }
    }
}
