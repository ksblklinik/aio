<?php

declare(strict_types=1);

namespace App\Modules\Finance\Contracts;

interface FinanceRegistryInterface
{
    /**
     * Catat transaksi penerimaan dana (Inflow / Uang Masuk).
     */
    public function recordInflow(
        int $accountId,
        float $amount,
        int $categoryId,
        ?int $contactId = null,
        string $description = '',
        ?string $referenceNumber = null,
        ?string $receiptPath = null,
        ?string $transactionDate = null
    ): int;

    /**
     * Catat transaksi pengeluaran dana (Outflow / Uang Keluar).
     * Jika $requiresApproval bernilai true, status transaksi menjadi 'pending_approval'
     * dan saldo kas belum dipotong hingga di-approve.
     */
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
    ): int;

    /**
     * Pindah dana antar akun kas / bank (Transfer).
     */
    public function transfer(
        int $sourceAccountId,
        int $targetAccountId,
        float $amount,
        string $description = '',
        ?string $referenceNumber = null,
        ?string $transactionDate = null
    ): int;

    /**
     * Setujui pengeluaran kas yang berstatus 'pending_approval'.
     */
    public function approveOutflow(int $transactionId, int $userId): void;

    /**
     * Tolak pengeluaran kas yang berstatus 'pending_approval'.
     */
    public function rejectOutflow(int $transactionId, int $userId, string $reason = ''): void;

    /**
     * Buat akun kas/bank baru.
     *
     * @param array<string, mixed> $data
     */
    public function createAccount(array $data): int;

    /**
     * Perbarui akun kas/bank.
     *
     * @param array<string, mixed> $data
     */
    public function updateAccount(int $accountId, array $data): bool;

    /**
     * Ambil saldo terkini sebuah akun.
     */
    public function getBalance(int $accountId): float;

    /**
     * Ambil detail sebuah akun.
     *
     * @return array<string, mixed>|null
     */
    public function getAccount(int $accountId): ?array;

    /**
     * Ambil daftar semua akun kas/bank aktif milik tenant.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAccounts(): array;

    /**
     * Buat kategori keuangan / COA baru.
     *
     * @param array<string, mixed> $data
     */
    public function createCategory(array $data): int;

    /**
     * Ambil daftar kategori keuangan berdasarkan jenis atau seluruhnya.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listCategories(?string $type = null): array;

    /**
     * Dapatkan detail transaksi berdasarkan ID.
     *
     * @return array<string, mixed>|null
     */
    public function getTransaction(int $transactionId): ?array;

    /**
     * Dapatkan daftar riwayat transaksi kas dengan filter.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listTransactions(array $filters = [], int $limit = 50, int $offset = 0): array;

    /**
     * Hasilkan Laporan Arus Kas (Cash Flow Statement).
     *
     * @return array<string, mixed>
     */
    public function getCashFlowStatement(string $startDate, string $endDate): array;

    /**
     * Hasilkan Laporan Laba Rugi Sederhana (Profit & Loss Statement).
     *
     * @return array<string, mixed>
     */
    public function getProfitLossStatement(string $startDate, string $endDate): array;

    /**
     * Hasilkan Rekening Koran / Buku Kas Per Akun (Account Ledger Statement).
     *
     * @return array<string, mixed>
     */
    public function getAccountLedgerStatement(int $accountId, string $startDate, string $endDate): array;
}
