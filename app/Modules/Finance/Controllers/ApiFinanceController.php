<?php

declare(strict_types=1);

namespace App\Modules\Finance\Controllers;

use App\Modules\Finance\Contracts\FinanceRegistryInterface;
use Core\Http\Request;
use Core\Http\Response;
use Throwable;

class ApiFinanceController
{
    public function __construct(
        private FinanceRegistryInterface $financeService
    ) {
    }

    public function accounts(): Response
    {
        return Response::json([
            'status' => 'success',
            'data' => $this->financeService->listAccounts(),
        ]);
    }

    public function categories(Request $request): Response
    {
        $type = $request->query('type') ? (string) $request->query('type') : null;
        return Response::json([
            'status' => 'success',
            'data' => $this->financeService->listCategories($type),
        ]);
    }

    public function transactions(Request $request): Response
    {
        $filters = [
            'type' => $request->query('type'),
            'status' => $request->query('status'),
            'account_id' => $request->query('account_id'),
            'category_id' => $request->query('category_id'),
            'contact_id' => $request->query('contact_id'),
            'start_date' => $request->query('start_date'),
            'end_date' => $request->query('end_date'),
        ];
        $limit = (int) $request->query('limit', 50);
        $offset = (int) $request->query('offset', 0);

        return Response::json([
            'status' => 'success',
            'data' => $this->financeService->listTransactions($filters, $limit, $offset),
        ]);
    }

    public function showTransaction(int $id): Response
    {
        $trx = $this->financeService->getTransaction($id);
        if (!$trx) {
            return Response::json(['status' => 'error', 'message' => 'Transaction not found.'], 404);
        }

        return Response::json([
            'status' => 'success',
            'data' => $trx,
        ]);
    }

    public function storeInflow(Request $request): Response
    {
        try {
            $id = $this->financeService->recordInflow(
                accountId: (int) $request->input('account_id'),
                amount: (float) $request->input('amount'),
                categoryId: (int) $request->input('category_id'),
                contactId: $request->input('contact_id') ? (int) $request->input('contact_id') : null,
                description: (string) $request->input('description', ''),
                referenceNumber: $request->input('reference_number') ? (string) $request->input('reference_number') : null,
                receiptPath: $request->input('receipt_path') ? (string) $request->input('receipt_path') : null,
                transactionDate: $request->input('transaction_date') ? (string) $request->input('transaction_date') : null
            );

            return Response::json([
                'status' => 'success',
                'message' => 'Inflow recorded successfully.',
                'data' => ['id' => $id],
            ], 201);
        } catch (Throwable $e) {
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function storeOutflow(Request $request): Response
    {
        try {
            $id = $this->financeService->recordOutflow(
                accountId: (int) $request->input('account_id'),
                amount: (float) $request->input('amount'),
                categoryId: (int) $request->input('category_id'),
                contactId: $request->input('contact_id') ? (int) $request->input('contact_id') : null,
                description: (string) $request->input('description', ''),
                referenceNumber: $request->input('reference_number') ? (string) $request->input('reference_number') : null,
                receiptPath: $request->input('receipt_path') ? (string) $request->input('receipt_path') : null,
                requiresApproval: (bool) $request->input('requires_approval', false),
                transactionDate: $request->input('transaction_date') ? (string) $request->input('transaction_date') : null
            );

            return Response::json([
                'status' => 'success',
                'message' => 'Outflow recorded successfully.',
                'data' => ['id' => $id],
            ], 201);
        } catch (Throwable $e) {
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function storeTransfer(Request $request): Response
    {
        try {
            $id = $this->financeService->transfer(
                sourceAccountId: (int) $request->input('source_account_id'),
                targetAccountId: (int) $request->input('target_account_id'),
                amount: (float) $request->input('amount'),
                description: (string) $request->input('description', ''),
                referenceNumber: $request->input('reference_number') ? (string) $request->input('reference_number') : null,
                transactionDate: $request->input('transaction_date') ? (string) $request->input('transaction_date') : null
            );

            return Response::json([
                'status' => 'success',
                'message' => 'Transfer executed successfully.',
                'data' => ['id' => $id],
            ], 201);
        } catch (Throwable $e) {
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function cashFlow(Request $request): Response
    {
        $startDate = (string) $request->query('start_date', date('Y-m-01'));
        $endDate = (string) $request->query('end_date', date('Y-m-d'));

        return Response::json([
            'status' => 'success',
            'data' => $this->financeService->getCashFlowStatement($startDate, $endDate),
        ]);
    }

    public function profitLoss(Request $request): Response
    {
        $startDate = (string) $request->query('start_date', date('Y-m-01'));
        $endDate = (string) $request->query('end_date', date('Y-m-d'));

        return Response::json([
            'status' => 'success',
            'data' => $this->financeService->getProfitLossStatement($startDate, $endDate),
        ]);
    }

    public function accountLedger(Request $request, int $accountId): Response
    {
        $startDate = (string) $request->query('start_date', date('Y-m-01'));
        $endDate = (string) $request->query('end_date', date('Y-m-d'));

        try {
            $statement = $this->financeService->getAccountLedgerStatement($accountId, $startDate, $endDate);
            return Response::json([
                'status' => 'success',
                'data' => $statement,
            ]);
        } catch (Throwable $e) {
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], 404);
        }
    }
}
