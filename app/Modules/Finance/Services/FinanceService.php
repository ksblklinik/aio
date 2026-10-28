<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Contracts\FinanceRegistryInterface;
use App\Modules\Finance\Models\FinancialAccount;
use App\Modules\Finance\Models\FinancialCategory;
use App\Modules\Finance\Models\FinancialTransaction;
use Core\Database\Connection;
use Core\Database\TenantContext;
use Core\Ledger\LedgerManager;
use RuntimeException;

class FinanceService implements FinanceRegistryInterface
{
    public const DEFAULT_CATEGORIES = [
        // Income
        ['code' => '4001', 'name' => 'Pendapatan Operasional / Usaha', 'type' => 'income'],
        ['code' => '4002', 'name' => 'Pendapatan Lain-lain / Non-Operasional', 'type' => 'income'],
        // Expense
        ['code' => '5001', 'name' => 'Beban Gaji & Upah', 'type' => 'expense'],
        ['code' => '5002', 'name' => 'Beban Listrik, Air & Internet', 'type' => 'expense'],
        ['code' => '5003', 'name' => 'Beban Sewa & Tempat', 'type' => 'expense'],
        ['code' => '5004', 'name' => 'Beban Transportasi & Bensin', 'type' => 'expense'],
        ['code' => '5005', 'name' => 'Beban Perlengkapan & ATK', 'type' => 'expense'],
        ['code' => '5006', 'name' => 'Beban Pemasaran & Iklan', 'type' => 'expense'],
        ['code' => '5007', 'name' => 'Beban Pemeliharaan & Perbaikan', 'type' => 'expense'],
        ['code' => '5008', 'name' => 'Beban Administrasi & Umum Lainnya', 'type' => 'expense'],
    ];

    private LedgerManager $ledgerManager;

    public function __construct(
        private Connection $db,
        private TenantContext $tenantContext,
        ?LedgerManager $ledgerManager = null
    ) {
        $this->ledgerManager = $ledgerManager ?? new LedgerManager($db, $tenantContext);
    }

    private function getTenantId(): int
    {
        if (!$this->tenantContext->hasTenant()) {
            throw new RuntimeException("TenantContext is required to operate on finance.");
        }
        return (int) $this->tenantContext->getTenantId();
    }

    public function createAccount(array $data): int
    {
        $tenantId = $this->getTenantId();
        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $type = (string) ($data['type'] ?? 'cash');

        if ($code === '' || $name === '') {
            throw new RuntimeException("Account code and name are required.");
        }

        $initialBalance = (float) ($data['initial_balance'] ?? 0.0);
        $accountNumber = !empty($data['account_number']) ? encrypt((string) $data['account_number']) : null;
        $now = date('Y-m-d H:i:s');

        $id = (int) $this->db->insert('financial_accounts', [
            'tenant_id' => $tenantId,
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'account_number' => $accountNumber,
            'bank_name' => $data['bank_name'] ?? null,
            'currency' => (string) ($data['currency'] ?? 'IDR'),
            'initial_balance' => $initialBalance,
            'current_balance' => $initialBalance,
            'is_active' => isset($data['is_active']) ? (int) $data['is_active'] : 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($initialBalance > 0.0) {
            $this->ledgerManager->record(
                'financial_account',
                (string) $id,
                $initialBalance,
                'credit',
                'initial_balance',
                (string) $id,
                "Saldo Awal Akun: {$name}",
                ['account_code' => $code],
                true,
                $tenantId
            );
        }

        return $id;
    }

    public function updateAccount(int $accountId, array $data): bool
    {
        $tenantId = $this->getTenantId();
        $account = $this->db->selectOne(
            "SELECT * FROM financial_accounts WHERE id = ? AND tenant_id = ?",
            [$accountId, $tenantId]
        );

        if (!$account) {
            throw new RuntimeException("Financial account not found.");
        }

        $fields = [];
        $params = [];

        if (isset($data['name'])) {
            $fields[] = 'name = ?';
            $params[] = trim((string) $data['name']);
        }
        if (isset($data['type'])) {
            $fields[] = 'type = ?';
            $params[] = (string) $data['type'];
        }
        if (array_key_exists('account_number', $data)) {
            $fields[] = 'account_number = ?';
            $params[] = !empty($data['account_number']) ? encrypt((string) $data['account_number']) : null;
        }
        if (array_key_exists('bank_name', $data)) {
            $fields[] = 'bank_name = ?';
            $params[] = $data['bank_name'];
        }
        if (isset($data['is_active'])) {
            $fields[] = 'is_active = ?';
            $params[] = (int) $data['is_active'];
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = 'updated_at = ?';
        $params[] = date('Y-m-d H:i:s');
        $params[] = $accountId;
        $params[] = $tenantId;

        $sql = "UPDATE financial_accounts SET " . implode(', ', $fields) . " WHERE id = ? AND tenant_id = ?";
        return $this->db->statement($sql, $params);
    }

    public function getBalance(int $accountId): float
    {
        $tenantId = $this->getTenantId();
        $row = $this->db->selectOne(
            "SELECT current_balance FROM financial_accounts WHERE id = ? AND tenant_id = ?",
            [$accountId, $tenantId]
        );

        return $row !== null ? (float) $row['current_balance'] : 0.0;
    }

    public function getAccount(int $accountId): ?array
    {
        $tenantId = $this->getTenantId();
        $row = $this->db->selectOne(
            "SELECT * FROM financial_accounts WHERE id = ? AND tenant_id = ?",
            [$accountId, $tenantId]
        );

        if (!$row) {
            return null;
        }

        return FinancialAccount::fromArray($row)->toArray();
    }

    public function listAccounts(): array
    {
        $tenantId = $this->getTenantId();
        $rows = $this->db->selectAll(
            "SELECT * FROM financial_accounts WHERE tenant_id = ? AND is_active = 1 ORDER BY id ASC",
            [$tenantId]
        );

        return array_map(fn($r) => FinancialAccount::fromArray($r)->toArray(), $rows);
    }

    public function createCategory(array $data): int
    {
        $tenantId = $this->getTenantId();
        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $type = (string) ($data['type'] ?? 'expense');

        if ($code === '' || $name === '') {
            throw new RuntimeException("Category code and name are required.");
        }

        $now = date('Y-m-d H:i:s');
        return (int) $this->db->insert('financial_categories', [
            'tenant_id' => $tenantId,
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'parent_id' => isset($data['parent_id']) ? (int) $data['parent_id'] : null,
            'is_active' => isset($data['is_active']) ? (int) $data['is_active'] : 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function listCategories(?string $type = null): array
    {
        $tenantId = $this->getTenantId();
        $this->seedDefaultCategoriesIfEmpty($tenantId);

        if ($type !== null && $type !== '') {
            $rows = $this->db->selectAll(
                "SELECT * FROM financial_categories WHERE tenant_id = ? AND type = ? AND is_active = 1 ORDER BY code ASC",
                [$tenantId, $type]
            );
        } else {
            $rows = $this->db->selectAll(
                "SELECT * FROM financial_categories WHERE tenant_id = ? AND is_active = 1 ORDER BY type ASC, code ASC",
                [$tenantId]
            );
        }

        return array_map(fn($r) => FinancialCategory::fromArray($r)->toArray(), $rows);
    }

    private function seedDefaultCategoriesIfEmpty(int $tenantId): void
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) as cnt FROM financial_categories WHERE tenant_id = ?",
            [$tenantId]
        );
        $count = $row ? (int) ($row['cnt'] ?? 0) : 0;

        if ($count === 0) {
            $now = date('Y-m-d H:i:s');
            foreach (self::DEFAULT_CATEGORIES as $cat) {
                $this->db->insert('financial_categories', [
                    'tenant_id' => $tenantId,
                    'code' => $cat['code'],
                    'name' => $cat['name'],
                    'type' => $cat['type'],
                    'parent_id' => null,
                    'is_active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function recordInflow(
        int $accountId,
        float $amount,
        int $categoryId,
        ?int $contactId = null,
        string $description = '',
        ?string $referenceNumber = null,
        ?string $receiptPath = null,
        ?string $transactionDate = null
    ): int {
        if ($amount <= 0.0) {
            throw new RuntimeException("Inflow amount must be greater than zero.");
        }

        $tenantId = $this->getTenantId();
        $account = $this->db->selectOne(
            "SELECT * FROM financial_accounts WHERE id = ? AND tenant_id = ? AND is_active = 1",
            [$accountId, $tenantId]
        );

        if (!$account) {
            throw new RuntimeException("Financial account not found or inactive.");
        }

        $trxNum = $this->generateTransactionNumber();
        $now = date('Y-m-d H:i:s');
        $date = $transactionDate ?: date('Y-m-d');

        return $this->db->transaction(function () use (
            $tenantId,
            $accountId,
            $amount,
            $categoryId,
            $contactId,
            $description,
            $referenceNumber,
            $receiptPath,
            $trxNum,
            $date,
            $now
        ) {
            $trxId = (int) $this->db->insert('financial_transactions', [
                'tenant_id' => $tenantId,
                'transaction_number' => $trxNum,
                'type' => 'inflow',
                'source_account_id' => $accountId,
                'target_account_id' => null,
                'category_id' => $categoryId,
                'contact_id' => $contactId,
                'amount' => $amount,
                'transaction_date' => $date,
                'status' => 'completed',
                'reference_number' => $referenceNumber,
                'description' => $description,
                'receipt_file_path' => $receiptPath,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Mutasi saldo kas bertambah
            $this->db->statement(
                "UPDATE financial_accounts SET current_balance = current_balance + ?, updated_at = ? WHERE id = ? AND tenant_id = ?",
                [$amount, $now, $accountId, $tenantId]
            );

            // Double-Entry Ledger Integration
            // 1. Debit Cash/Account (Saldo aset bertambah di Ledger)
            $this->ledgerManager->record(
                'financial_account',
                (string) $accountId,
                $amount,
                'credit',
                'finance_inflow',
                (string) $trxId,
                $description ?: 'Penerimaan Kas',
                ['transaction_number' => $trxNum, 'type' => 'inflow'],
                true,
                $tenantId
            );

            // 2. Kredit Kategori Pendapatan
            $this->ledgerManager->record(
                'financial_category',
                (string) $categoryId,
                $amount,
                'credit',
                'finance_inflow',
                (string) $trxId,
                $description ?: 'Penerimaan Kas',
                ['transaction_number' => $trxNum, 'type' => 'inflow'],
                true,
                $tenantId
            );

            return $trxId;
        });
    }

    public function recordOutflow(
        int $accountId,
        float $amount,
        int $categoryId,
        ?int $contactId = null,
        string $description = '',
        ?string $referenceNumber = null,
        ?string $receiptPath = null,
        bool $requiresApproval = false,
        ?string $transactionDate = null
    ): int {
        if ($amount <= 0.0) {
            throw new RuntimeException("Outflow amount must be greater than zero.");
        }

        $tenantId = $this->getTenantId();
        $account = $this->db->selectOne(
            "SELECT * FROM financial_accounts WHERE id = ? AND tenant_id = ? AND is_active = 1",
            [$accountId, $tenantId]
        );

        if (!$account) {
            throw new RuntimeException("Financial account not found or inactive.");
        }

        $trxNum = $this->generateTransactionNumber();
        $now = date('Y-m-d H:i:s');
        $date = $transactionDate ?: date('Y-m-d');

        if ($requiresApproval) {
            return (int) $this->db->insert('financial_transactions', [
                'tenant_id' => $tenantId,
                'transaction_number' => $trxNum,
                'type' => 'outflow',
                'source_account_id' => $accountId,
                'target_account_id' => null,
                'category_id' => $categoryId,
                'contact_id' => $contactId,
                'amount' => $amount,
                'transaction_date' => $date,
                'status' => 'pending_approval',
                'reference_number' => $referenceNumber,
                'description' => $description,
                'receipt_file_path' => $receiptPath,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Outflow langsung (tanpa approval)
        if ((float) $account['current_balance'] < $amount) {
            throw new RuntimeException(
                "Saldo kas tidak mencukupi untuk pengeluaran ini. Saldo: {$account['current_balance']}, Diminta: {$amount}"
            );
        }

        return $this->db->transaction(function () use (
            $tenantId,
            $accountId,
            $amount,
            $categoryId,
            $contactId,
            $description,
            $referenceNumber,
            $receiptPath,
            $trxNum,
            $date,
            $now
        ) {
            $trxId = (int) $this->db->insert('financial_transactions', [
                'tenant_id' => $tenantId,
                'transaction_number' => $trxNum,
                'type' => 'outflow',
                'source_account_id' => $accountId,
                'target_account_id' => null,
                'category_id' => $categoryId,
                'contact_id' => $contactId,
                'amount' => $amount,
                'transaction_date' => $date,
                'status' => 'completed',
                'reference_number' => $referenceNumber,
                'description' => $description,
                'receipt_file_path' => $receiptPath,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Mutasi saldo kas berkurang secara atomik bersyarat
            $affected = $this->db->affectingStatement(
                "UPDATE financial_accounts SET current_balance = round(current_balance - ?, 2), updated_at = ? WHERE id = ? AND tenant_id = ? AND current_balance >= ?",
                [$amount, $now, $accountId, $tenantId, $amount]
            );

            if ($affected !== 1) {
                throw new RuntimeException("Saldo kas tidak mencukupi untuk pengeluaran ini.");
            }

            // Double-Entry Ledger Integration
            $this->ledgerManager->record(
                'financial_account',
                (string) $accountId,
                $amount,
                'debit',
                'finance_outflow',
                (string) $trxId,
                $description ?: 'Pengeluaran Kas',
                ['transaction_number' => $trxNum, 'type' => 'outflow'],
                false,
                $tenantId
            );

            $this->ledgerManager->record(
                'financial_category',
                (string) $categoryId,
                $amount,
                'debit',
                'finance_outflow',
                (string) $trxId,
                $description ?: 'Pengeluaran Kas',
                ['transaction_number' => $trxNum, 'type' => 'outflow'],
                true,
                $tenantId
            );

            return $trxId;
        });
    }

    public function transfer(
        int $sourceAccountId,
        int $targetAccountId,
        float $amount,
        string $description = '',
        ?string $referenceNumber = null,
        ?string $transactionDate = null
    ): int {
        if ($sourceAccountId === $targetAccountId) {
            throw new RuntimeException("Akun sumber dan akun tujuan tidak boleh sama.");
        }

        if ($amount <= 0.0) {
            throw new RuntimeException("Transfer amount must be greater than zero.");
        }

        $tenantId = $this->getTenantId();
        $source = $this->db->selectOne(
            "SELECT * FROM financial_accounts WHERE id = ? AND tenant_id = ? AND is_active = 1",
            [$sourceAccountId, $tenantId]
        );
        $target = $this->db->selectOne(
            "SELECT * FROM financial_accounts WHERE id = ? AND tenant_id = ? AND is_active = 1",
            [$targetAccountId, $tenantId]
        );

        if (!$source || !$target) {
            throw new RuntimeException("Akun sumber atau akun tujuan tidak valid.");
        }

        if ((float) $source['current_balance'] < $amount) {
            throw new RuntimeException(
                "Saldo akun sumber tidak mencukupi untuk transfer. Saldo: {$source['current_balance']}, Diminta: {$amount}"
            );
        }

        $trxNum = $this->generateTransactionNumber();
        $now = date('Y-m-d H:i:s');
        $date = $transactionDate ?: date('Y-m-d');

        return $this->db->transaction(function () use (
            $tenantId,
            $sourceAccountId,
            $targetAccountId,
            $amount,
            $description,
            $referenceNumber,
            $trxNum,
            $date,
            $now
        ) {
            $trxId = (int) $this->db->insert('financial_transactions', [
                'tenant_id' => $tenantId,
                'transaction_number' => $trxNum,
                'type' => 'transfer',
                'source_account_id' => $sourceAccountId,
                'target_account_id' => $targetAccountId,
                'category_id' => null,
                'contact_id' => null,
                'amount' => $amount,
                'transaction_date' => $date,
                'status' => 'completed',
                'reference_number' => $referenceNumber,
                'description' => $description ?: 'Transfer antar rekening kas',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Kurangi akun sumber & tambah akun tujuan
            $this->db->statement(
                "UPDATE financial_accounts SET current_balance = current_balance - ?, updated_at = ? WHERE id = ? AND tenant_id = ?",
                [$amount, $now, $sourceAccountId, $tenantId]
            );
            $this->db->statement(
                "UPDATE financial_accounts SET current_balance = current_balance + ?, updated_at = ? WHERE id = ? AND tenant_id = ?",
                [$amount, $now, $targetAccountId, $tenantId]
            );

            // Double-entry ledger
            $this->ledgerManager->record(
                'financial_account',
                (string) $sourceAccountId,
                $amount,
                'debit',
                'finance_transfer',
                (string) $trxId,
                $description ?: 'Transfer Keluar',
                ['transaction_number' => $trxNum, 'type' => 'transfer_out'],
                false,
                $tenantId
            );

            $this->ledgerManager->record(
                'financial_account',
                (string) $targetAccountId,
                $amount,
                'credit',
                'finance_transfer',
                (string) $trxId,
                $description ?: 'Transfer Masuk',
                ['transaction_number' => $trxNum, 'type' => 'transfer_in'],
                true,
                $tenantId
            );

            return $trxId;
        });
    }

    public function approveOutflow(int $transactionId, int $userId): void
    {
        $tenantId = $this->getTenantId();
        $trx = $this->db->selectOne(
            "SELECT * FROM financial_transactions WHERE id = ? AND tenant_id = ?",
            [$transactionId, $tenantId]
        );

        if (!$trx) {
            throw new RuntimeException("Transaksi tidak ditemukan.");
        }

        if ($trx['status'] !== 'pending_approval') {
            throw new RuntimeException("Hanya transaksi dengan status pending_approval yang dapat disetujui.");
        }

        $sourceAccountId = (int) $trx['source_account_id'];
        $amount = (float) $trx['amount'];

        $account = $this->db->selectOne(
            "SELECT * FROM financial_accounts WHERE id = ? AND tenant_id = ?",
            [$sourceAccountId, $tenantId]
        );

        if (!$account || (float) $account['current_balance'] < $amount) {
            throw new RuntimeException("Saldo kas tidak mencukupi untuk menyetujui transaksi ini.");
        }

        $now = date('Y-m-d H:i:s');

        $this->db->transaction(function () use (
            $tenantId,
            $transactionId,
            $userId,
            $sourceAccountId,
            $amount,
            $trx,
            $now
        ) {
            $this->db->statement(
                "UPDATE financial_transactions SET status = 'completed', approved_by = ?, approved_at = ?, updated_at = ? WHERE id = ? AND tenant_id = ?",
                [$userId, $now, $now, $transactionId, $tenantId]
            );

            $this->db->statement(
                "UPDATE financial_accounts SET current_balance = current_balance - ?, updated_at = ? WHERE id = ? AND tenant_id = ?",
                [$amount, $now, $sourceAccountId, $tenantId]
            );

            $this->ledgerManager->record(
                'financial_account',
                (string) $sourceAccountId,
                $amount,
                'debit',
                'finance_outflow',
                (string) $transactionId,
                $trx['description'] ?: 'Pengeluaran Kas Disetujui',
                ['transaction_number' => $trx['transaction_number'], 'approved_by' => $userId],
                false,
                $tenantId
            );

            if (!empty($trx['category_id'])) {
                $this->ledgerManager->record(
                    'financial_category',
                    (string) $trx['category_id'],
                    $amount,
                    'debit',
                    'finance_outflow',
                    (string) $transactionId,
                    $trx['description'] ?: 'Pengeluaran Kas Disetujui',
                    ['transaction_number' => $trx['transaction_number'], 'approved_by' => $userId],
                    true,
                    $tenantId
                );
            }
        });
    }

    public function rejectOutflow(int $transactionId, int $userId, string $reason = ''): void
    {
        $tenantId = $this->getTenantId();
        $trx = $this->db->selectOne(
            "SELECT * FROM financial_transactions WHERE id = ? AND tenant_id = ?",
            [$transactionId, $tenantId]
        );

        if (!$trx) {
            throw new RuntimeException("Transaksi tidak ditemukan.");
        }

        if ($trx['status'] !== 'pending_approval') {
            throw new RuntimeException("Hanya transaksi dengan status pending_approval yang dapat ditolak.");
        }

        $now = date('Y-m-d H:i:s');
        $desc = (string) $trx['description'];
        if ($reason !== '') {
            $desc .= " (Ditolak: {$reason})";
        }

        $this->db->statement(
            "UPDATE financial_transactions SET status = 'cancelled', description = ?, updated_at = ? WHERE id = ? AND tenant_id = ?",
            [$desc, $now, $transactionId, $tenantId]
        );
    }

    public function getTransaction(int $transactionId): ?array
    {
        $tenantId = $this->getTenantId();
        $row = $this->db->selectOne(
            "SELECT * FROM financial_transactions WHERE id = ? AND tenant_id = ?",
            [$transactionId, $tenantId]
        );

        return $row ? FinancialTransaction::fromArray($row)->toArray() : null;
    }

    public function listTransactions(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $tenantId = $this->getTenantId();
        $sql = "SELECT * FROM financial_transactions WHERE tenant_id = ?";
        $params = [$tenantId];

        if (!empty($filters['type'])) {
            $sql .= " AND type = ?";
            $params[] = $filters['type'];
        }
        if (!empty($filters['status'])) {
            $sql .= " AND status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['account_id'])) {
            $sql .= " AND (source_account_id = ? OR target_account_id = ?)";
            $params[] = (int) $filters['account_id'];
            $params[] = (int) $filters['account_id'];
        }
        if (!empty($filters['category_id'])) {
            $sql .= " AND category_id = ?";
            $params[] = (int) $filters['category_id'];
        }
        if (!empty($filters['contact_id'])) {
            $sql .= " AND contact_id = ?";
            $params[] = (int) $filters['contact_id'];
        }
        if (!empty($filters['start_date'])) {
            $sql .= " AND transaction_date >= ?";
            $params[] = $filters['start_date'];
        }
        if (!empty($filters['end_date'])) {
            $sql .= " AND transaction_date <= ?";
            $params[] = $filters['end_date'];
        }

        $sql .= " ORDER BY transaction_date DESC, id DESC LIMIT ? OFFSET ?";
        $params[] = max(1, $limit);
        $params[] = max(0, $offset);

        $rows = $this->db->selectAll($sql, $params);
        return array_map(fn($r) => FinancialTransaction::fromArray($r)->toArray(), $rows);
    }

    public function getCashFlowStatement(string $startDate, string $endDate): array
    {
        $tenantId = $this->getTenantId();

        // Total Inflow by Category
        $inflows = $this->db->selectAll(
            "SELECT c.name as category_name, c.code as category_code, SUM(t.amount) as total_amount
             FROM financial_transactions t
             LEFT JOIN financial_categories c ON t.category_id = c.id
             WHERE t.tenant_id = ? AND t.type = 'inflow' AND t.status = 'completed'
               AND t.transaction_date >= ? AND t.transaction_date <= ?
             GROUP BY c.id, c.name, c.code
             ORDER BY total_amount DESC",
            [$tenantId, $startDate, $endDate]
        );

        // Total Outflow by Category
        $outflows = $this->db->selectAll(
            "SELECT c.name as category_name, c.code as category_code, SUM(t.amount) as total_amount
             FROM financial_transactions t
             LEFT JOIN financial_categories c ON t.category_id = c.id
             WHERE t.tenant_id = ? AND t.type = 'outflow' AND t.status = 'completed'
               AND t.transaction_date >= ? AND t.transaction_date <= ?
             GROUP BY c.id, c.name, c.code
             ORDER BY total_amount DESC",
            [$tenantId, $startDate, $endDate]
        );

        $totalInflow = 0.0;
        foreach ($inflows as &$inf) {
            $inf['total_amount'] = (float) $inf['total_amount'];
            $totalInflow += $inf['total_amount'];
        }
        unset($inf);

        $totalOutflow = 0.0;
        foreach ($outflows as &$out) {
            $out['total_amount'] = (float) $out['total_amount'];
            $totalOutflow += $out['total_amount'];
        }
        unset($out);

        $netCashFlow = round($totalInflow - $totalOutflow, 4);
        $accounts = $this->listAccounts();

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'inflows' => $inflows,
            'outflows' => $outflows,
            'total_inflow' => $totalInflow,
            'total_outflow' => $totalOutflow,
            'net_cash_flow' => $netCashFlow,
            'accounts' => $accounts,
        ];
    }

    public function getProfitLossStatement(string $startDate, string $endDate): array
    {
        $tenantId = $this->getTenantId();

        // Incomes (type = income)
        $revenues = $this->db->selectAll(
            "SELECT c.name as category_name, c.code as category_code, SUM(t.amount) as total_amount
             FROM financial_transactions t
             JOIN financial_categories c ON t.category_id = c.id
             WHERE t.tenant_id = ? AND t.type = 'inflow' AND c.type = 'income' AND t.status = 'completed'
               AND t.transaction_date >= ? AND t.transaction_date <= ?
             GROUP BY c.id, c.name, c.code
             ORDER BY total_amount DESC",
            [$tenantId, $startDate, $endDate]
        );

        // Expenses (type = expense)
        $expenses = $this->db->selectAll(
            "SELECT c.name as category_name, c.code as category_code, SUM(t.amount) as total_amount
             FROM financial_transactions t
             JOIN financial_categories c ON t.category_id = c.id
             WHERE t.tenant_id = ? AND t.type = 'outflow' AND c.type = 'expense' AND t.status = 'completed'
               AND t.transaction_date >= ? AND t.transaction_date <= ?
             GROUP BY c.id, c.name, c.code
             ORDER BY total_amount DESC",
            [$tenantId, $startDate, $endDate]
        );

        $totalRevenue = 0.0;
        foreach ($revenues as &$rev) {
            $rev['total_amount'] = (float) $rev['total_amount'];
            $totalRevenue += $rev['total_amount'];
        }
        unset($rev);

        $totalExpense = 0.0;
        foreach ($expenses as &$exp) {
            $exp['total_amount'] = (float) $exp['total_amount'];
            $totalExpense += $exp['total_amount'];
        }
        unset($exp);

        $netProfit = round($totalRevenue - $totalExpense, 4);

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'revenues' => $revenues,
            'expenses' => $expenses,
            'total_revenue' => $totalRevenue,
            'total_expense' => $totalExpense,
            'net_profit' => $netProfit,
        ];
    }

    public function getAccountLedgerStatement(int $accountId, string $startDate, string $endDate): array
    {
        $tenantId = $this->getTenantId();
        $account = $this->db->selectOne(
            "SELECT * FROM financial_accounts WHERE id = ? AND tenant_id = ?",
            [$accountId, $tenantId]
        );

        if (!$account) {
            throw new RuntimeException("Account not found.");
        }

        $initialBalance = (float) $account['initial_balance'];

        // Prior mutations before startDate
        $priorInflows = $this->queryScalar(
            "SELECT COALESCE(SUM(amount), 0) FROM financial_transactions
             WHERE tenant_id = ? AND source_account_id = ? AND type = 'inflow' AND status = 'completed'
               AND transaction_date < ?",
            [$tenantId, $accountId, $startDate]
        );

        $priorTransferIn = $this->queryScalar(
            "SELECT COALESCE(SUM(amount), 0) FROM financial_transactions
             WHERE tenant_id = ? AND target_account_id = ? AND type = 'transfer' AND status = 'completed'
               AND transaction_date < ?",
            [$tenantId, $accountId, $startDate]
        );

        $priorOutflows = $this->queryScalar(
            "SELECT COALESCE(SUM(amount), 0) FROM financial_transactions
             WHERE tenant_id = ? AND source_account_id = ? AND type = 'outflow' AND status = 'completed'
               AND transaction_date < ?",
            [$tenantId, $accountId, $startDate]
        );

        $priorTransferOut = $this->queryScalar(
            "SELECT COALESCE(SUM(amount), 0) FROM financial_transactions
             WHERE tenant_id = ? AND source_account_id = ? AND type = 'transfer' AND status = 'completed'
               AND transaction_date < ?",
            [$tenantId, $accountId, $startDate]
        );

        $beginningBalance = round($initialBalance + $priorInflows + $priorTransferIn - $priorOutflows - $priorTransferOut, 4);

        // Fetch transactions within range
        $rows = $this->db->selectAll(
            "SELECT * FROM financial_transactions
             WHERE tenant_id = ? AND status = 'completed'
               AND (source_account_id = ? OR target_account_id = ?)
               AND transaction_date >= ? AND transaction_date <= ?
             ORDER BY transaction_date ASC, id ASC",
            [$tenantId, $accountId, $accountId, $startDate, $endDate]
        );

        $runningBalance = $beginningBalance;
        $entries = [];

        foreach ($rows as $r) {
            $amount = (float) $r['amount'];
            $type = $r['type'];
            $isSource = (int) $r['source_account_id'] === $accountId;

            if ($type === 'inflow') {
                $runningBalance += $amount;
                $direction = 'credit';
            } elseif ($type === 'outflow') {
                $runningBalance -= $amount;
                $direction = 'debit';
            } elseif ($type === 'transfer') {
                if ($isSource) {
                    $runningBalance -= $amount;
                    $direction = 'debit';
                } else {
                    $runningBalance += $amount;
                    $direction = 'credit';
                }
            } else {
                $direction = 'neutral';
            }

            $entries[] = [
                'id' => (int) $r['id'],
                'transaction_number' => $r['transaction_number'],
                'date' => $r['transaction_date'],
                'type' => $type,
                'direction' => $direction,
                'amount' => $amount,
                'description' => $r['description'],
                'balance_after' => round($runningBalance, 4),
            ];
        }

        return [
            'account' => FinancialAccount::fromArray($account)->toArray(),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'beginning_balance' => $beginningBalance,
            'entries' => $entries,
            'ending_balance' => round($runningBalance, 4),
        ];
    }

    private function queryScalar(string $sql, array $params = []): float
    {
        $row = $this->db->selectOne($sql, $params);
        if (!$row) {
            return 0.0;
        }
        $val = reset($row);
        return $val !== false && $val !== null ? (float) $val : 0.0;
    }

    private function generateTransactionNumber(): string
    {
        if (function_exists('sequence')) {
            try {
                return sequence('finance', 'TRX', 5, 'yearly');
            } catch (\Throwable) {
                // fallback
            }
        }
        return 'TRX/' . date('Y') . '/' . str_pad((string) mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
    }
}
