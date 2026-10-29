<?php

declare(strict_types=1);

namespace Core\Ledger;

use Core\Database\Connection;
use Core\Database\TenantContext;
use InvalidArgumentException;
use RuntimeException;

class LedgerManager
{
    public function __construct(
        private Connection $db,
        private ?TenantContext $context = null
    ) {
        $this->context = $context ?? TenantContext::getInstance();
    }

    private function resolveTenantId(?int $tenantId): int
    {
        if ($tenantId !== null) {
            return $tenantId;
        }

        $active = $this->context->getTenantId();
        return $active !== null ? (int) $active : 0;
    }

    /**
     * Catat mutasi saldo atomik terisolasi tenant dengan jaminan konsistensi transaksi
     *
     * @param string $accountType Jenis akun (e.g. 'driver_wallet', 'customer_receivable', 'cash', 'commission')
     * @param string $accountId ID entitas pemilik akun (e.g. driver_id, customer_id, store_id)
     * @param float $amount Nominal mutasi (harus bernilai positif)
     * @param string $direction Arah mutasi: 'credit' (penambahan saldo) atau 'debit' (pengurangan saldo)
     * @param string|null $referenceType Jenis dokumen referensi (e.g. 'order', 'topup', 'withdrawal', 'invoice')
     * @param string|null $referenceId ID dokumen referensi
     * @param string|null $description Catatan / deskripsi mutasi
     * @param array<string, mixed> $metadata Payload metadata tambahan
     * @param bool $allowNegative Izinkan saldo menjadi minus (default: false / dilindungi dari overdraft)
     * @param int|null $tenantId Override tenant ID
     * @return array{id: int, ledger_id: int, direction: string, amount: float, balance_after: float}
     */
    public function record(
        string $accountType,
        string $accountId,
        float $amount,
        string $direction,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $description = null,
        array $metadata = [],
        bool $allowNegative = false,
        ?int $tenantId = null
    ): array {
        if ($amount <= 0.0) {
            throw new InvalidArgumentException("Mutation amount must be strictly greater than zero.");
        }

        $cleanDir = strtolower(trim($direction));
        if ($cleanDir !== 'credit' && $cleanDir !== 'debit') {
            throw new InvalidArgumentException("Direction must be either 'credit' or 'debit'. Received: {$direction}");
        }

        $resolvedTenantId = $this->resolveTenantId($tenantId);

        return $this->db->transaction(function () use (
            $accountType,
            $accountId,
            $amount,
            $cleanDir,
            $referenceType,
            $referenceId,
            $description,
            $metadata,
            $allowNegative,
            $resolvedTenantId
        ) {
            $now = date('Y-m-d H:i:s');

            // 1. Pastikan akun ledger sudah ada (Atomic Get-or-Create)
            $this->db->statement(
                "INSERT INTO ledgers (tenant_id, account_type, account_id, currency, balance, created_at, updated_at)
                 VALUES (?, ?, ?, 'IDR', 0.0, ?, ?)
                 ON CONFLICT(tenant_id, account_type, account_id) DO NOTHING",
                [$resolvedTenantId, $accountType, $accountId, $now, $now]
            );

            // 2. Kunci dan ambil saldo saat ini
            $ledger = $this->db->selectOne(
                "SELECT id, balance FROM ledgers WHERE tenant_id = ? AND account_type = ? AND account_id = ?",
                [$resolvedTenantId, $accountType, $accountId]
            );

            if (!$ledger) {
                throw new RuntimeException("Could not lock ledger for account [{$accountType}:{$accountId}].");
            }

            $ledgerId = (int) $ledger['id'];
            $currentBalance = (float) $ledger['balance'];

            // 3. Hitung saldo baru menggunakan fixed-point integer scaling (skala 4 desimal) untuk mencegah IEEE-754 precision drift
            $scale = 10000;
            $currInt = (int) round($currentBalance * $scale);
            $amountInt = (int) round($amount * $scale);
            $changeInt = $cleanDir === 'credit' ? $amountInt : -$amountInt;
            $newInt = $currInt + $changeInt;
            $newBalance = (float) ($newInt / $scale);

            if (!$allowNegative && $newBalance < 0.0) {
                throw new RuntimeException(
                    "Insufficient balance for account [{$accountType}:{$accountId}]. Current balance: {$currentBalance}, attempted debit: {$amount}."
                );
            }

            // 4. Update saldo ledger
            $this->db->statement(
                "UPDATE ledgers SET balance = ?, updated_at = ? WHERE id = ?",
                [$newBalance, $now, $ledgerId]
            );

            // 5. Catat entri riwayat mutasi
            $metadataJson = !empty($metadata) ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
            $entryId = $this->db->insert('ledger_entries', [
                'tenant_id' => $resolvedTenantId,
                'ledger_id' => $ledgerId,
                'direction' => $cleanDir,
                'amount' => $amount,
                'balance_after' => $newBalance,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description,
                'metadata' => $metadataJson,
                'created_at' => $now,
            ]);

            return [
                'id' => (int) $entryId,
                'ledger_id' => $ledgerId,
                'direction' => $cleanDir,
                'amount' => $amount,
                'balance_after' => $newBalance,
            ];
        });
    }

    /**
     * Dapatkan saldo terkini untuk suatu akun ledger
     */
    public function getBalance(string $accountType, string $accountId, ?int $tenantId = null): float
    {
        $resolvedTenantId = $this->resolveTenantId($tenantId);
        $row = $this->db->selectOne(
            "SELECT balance FROM ledgers WHERE tenant_id = ? AND account_type = ? AND account_id = ?",
            [$resolvedTenantId, $accountType, $accountId]
        );

        return $row !== null ? (float) $row['balance'] : 0.0;
    }

    /**
     * Dapatkan rekening koran / riwayat transaksi mutasi buku besar
     *
     * @return array<int, array<string, mixed>>
     */
    public function getStatement(
        string $accountType,
        string $accountId,
        int $limit = 50,
        ?int $tenantId = null
    ): array {
        $resolvedTenantId = $this->resolveTenantId($tenantId);
        $sql = "SELECT e.* FROM ledger_entries e
                JOIN ledgers l ON e.ledger_id = l.id
                WHERE l.tenant_id = ? AND l.account_type = ? AND l.account_id = ?
                ORDER BY e.id DESC LIMIT ?";

        return $this->db->selectAll($sql, [$resolvedTenantId, $accountType, $accountId, max(1, $limit)]);
    }
}
