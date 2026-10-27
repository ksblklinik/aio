<?php

declare(strict_types=1);

namespace Core\Payment;

use Core\Database\Connection;
use Core\Database\TenantContext;
use Core\Ledger\LedgerManager;
use InvalidArgumentException;
use RuntimeException;

class PaymentManager
{
    /** @var array<string, PaymentDriverInterface> */
    private array $drivers = [];

    private string $defaultDriver = 'mock';

    public function __construct(
        private Connection $db,
        private ?LedgerManager $ledgerManager = null,
        private ?TenantContext $context = null
    ) {
        $this->context = $context ?? TenantContext::getInstance();

        // Register default drivers
        $this->registerDefaultDrivers();
    }

    private function registerDefaultDrivers(): void
    {
        $this->drivers['mock'] = new MockPaymentDriver();
        $this->drivers['manual'] = new ManualTransferDriver();
        $this->drivers['kasera'] = new KaseraPaymentDriver();
    }

    public function extend(string $name, PaymentDriverInterface $driver): self
    {
        $this->drivers[$name] = $driver;
        return $this;
    }

    public function setDefaultDriver(string $name): self
    {
        $this->defaultDriver = $name;
        return $this;
    }

    public function driver(?string $name = null): PaymentDriverInterface
    {
        $driverName = $name ?? $this->defaultDriver;
        if (!isset($this->drivers[$driverName])) {
            throw new InvalidArgumentException("Payment driver '{$driverName}' is not registered.");
        }

        return $this->drivers[$driverName];
    }

    private function resolveTenantId(?int $tenantId): int
    {
        if ($tenantId !== null) {
            return $tenantId;
        }

        $active = $this->context->getTenantId();
        return $active !== null ? (int) $active : 0;
    }

    private function getLedgerManager(): LedgerManager
    {
        if ($this->ledgerManager === null) {
            $this->ledgerManager = new LedgerManager($this->db, $this->context);
        }

        return $this->ledgerManager;
    }

    /**
     * Inisiasi transaksi pembayaran baru
     */
    public function charge(PaymentRequest $request, ?string $driverName = null): PaymentResponse
    {
        if ($request->amount <= 0.0) {
            throw new InvalidArgumentException("Payment amount must be greater than zero.");
        }

        $activeDriverName = $driverName ?? $this->defaultDriver;
        $driver = $this->driver($activeDriverName);

        $tenantId = $request->tenantId > 0 ? $request->tenantId : $this->resolveTenantId(null);
        $metaJson = json_encode($request->metadata);

        // Simpan transaksi pending ke database
        $stmt = $this->db->getPdo()->prepare("
            INSERT INTO payment_transactions (
                tenant_id, reference_no, driver, amount, currency, status,
                payment_method, customer_name, customer_email, customer_phone,
                metadata, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");

        $stmt->execute([
            $tenantId,
            $request->referenceNo,
            $activeDriverName,
            $request->amount,
            $request->currency,
            $request->paymentMethod,
            $request->customerName,
            $request->customerEmail,
            $request->customerPhone,
            $metaJson,
        ]);

        $txnId = (int) $this->db->getPdo()->lastInsertId();

        // Panggil driver gateway
        $response = $driver->charge($request);

        // Update record dengan gateway reference & checkout URL
        $updateStmt = $this->db->getPdo()->prepare("
            UPDATE payment_transactions
            SET gateway_reference = ?, checkout_url = ?, status = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");

        $updateStmt->execute([
            $response->gatewayReference,
            $response->checkoutUrl,
            $response->status,
            $txnId,
        ]);

        return $response;
    }

    /**
     * Memproses callback webhook gateway secara idempotent & rekonsiliasi ledger
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $headers
     */
    public function handleWebhook(array $payload, array $headers = [], ?string $driverName = null): WebhookResult
    {
        $activeDriverName = $driverName ?? $this->defaultDriver;
        $driver = $this->driver($activeDriverName);

        $result = $driver->verifyWebhook($payload, $headers);
        if (!$result->isValid) {
            return $result;
        }

        $stmt = $this->db->getPdo()->prepare("SELECT * FROM payment_transactions WHERE reference_no = ?");
        $stmt->execute([$result->referenceNo]);
        $txn = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$txn) {
            return new WebhookResult(
                isValid: false,
                referenceNo: $result->referenceNo,
                status: 'failed',
                errorMessage: "Transaction with reference {$result->referenceNo} not found."
            );
        }

        // Idempotency: Jika status transaksi sudah PAID, abaikan webhook ganda
        if ($txn['status'] === 'paid') {
            return $result;
        }

        // Update status transaksi
        $isPaid = ($result->status === 'paid');
        $paidAt = $isPaid ? date('Y-m-d H:i:s') : null;

        $updateStmt = $this->db->getPdo()->prepare("
            UPDATE payment_transactions
            SET status = ?, gateway_reference = COALESCE(?, gateway_reference),
                payment_method = COALESCE(?, payment_method), paid_at = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");

        $updateStmt->execute([
            $result->status,
            $result->gatewayReference,
            $result->paymentMethod,
            $paidAt,
            $txn['id'],
        ]);

        // Rekonsiliasi atomik otomatis ke LedgerManager jika diaktifkan dalam metadata
        if ($isPaid) {
            $metadata = !empty($txn['metadata']) ? json_decode((string) $txn['metadata'], true) : [];
            if (!empty($metadata['ledger_reconciliation'])) {
                $ledger = $this->getLedgerManager();
                $accType = $metadata['ledger_account_type'] ?? 'cash_clearing';
                $accId = (string) ($metadata['ledger_account_id'] ?? $txn['tenant_id']);
                $direction = $metadata['ledger_direction'] ?? 'credit';

                $ledger->record(
                    accountType: $accType,
                    accountId: $accId,
                    amount: (float) $txn['amount'],
                    direction: $direction,
                    referenceType: 'payment',
                    referenceId: (string) $txn['reference_no'],
                    description: "Payment received for {$txn['reference_no']}",
                    metadata: [
                        'driver' => $txn['driver'],
                        'payment_method' => $result->paymentMethod,
                        'gateway_reference' => $result->gatewayReference,
                    ],
                    allowNegative: false,
                    tenantId: (int) $txn['tenant_id']
                );
            }
        }

        return $result;
    }

    /**
     * Dapatkan detail transaksi berdasarkan reference_no
     *
     * @return array<string, mixed>|null
     */
    public function getTransaction(string $referenceNo): ?array
    {
        $stmt = $this->db->getPdo()->prepare("SELECT * FROM payment_transactions WHERE reference_no = ?");
        $stmt->execute([$referenceNo]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Periksa status transaksi
     */
    public function checkStatus(string $referenceNo): string
    {
        $txn = $this->getTransaction($referenceNo);
        if (!$txn) {
            return 'not_found';
        }

        if ($txn['status'] === 'paid' || $txn['status'] === 'failed' || $txn['status'] === 'refunded') {
            return $txn['status'];
        }

        $driver = $this->driver($txn['driver']);
        $gwRef = $txn['gateway_reference'] ?? $referenceNo;
        $status = $driver->checkStatus($gwRef);

        if ($status !== $txn['status']) {
            $stmt = $this->db->getPdo()->prepare("
                UPDATE payment_transactions
                SET status = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$status, $txn['id']]);
        }

        return $status;
    }

    /**
     * Lakukan refund transaksi yang telah lunas
     */
    public function refund(string $referenceNo, float $amount, string $reason = ''): bool
    {
        $txn = $this->getTransaction($referenceNo);
        if (!$txn || $txn['status'] !== 'paid') {
            return false;
        }

        $driver = $this->driver($txn['driver']);
        $gwRef = $txn['gateway_reference'] ?? $referenceNo;
        $ok = $driver->refund($gwRef, $amount, $reason);

        if ($ok) {
            $stmt = $this->db->getPdo()->prepare("
                UPDATE payment_transactions
                SET status = 'refunded', updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$txn['id']]);

            // Reverse ledger mutation jika rekonsiliasi ada
            $metadata = !empty($txn['metadata']) ? json_decode((string) $txn['metadata'], true) : [];
            if (!empty($metadata['ledger_reconciliation'])) {
                $ledger = $this->getLedgerManager();
                $accType = $metadata['ledger_account_type'] ?? 'cash_clearing';
                $accId = (string) ($metadata['ledger_account_id'] ?? $txn['tenant_id']);
                // Kebalikan arah mutasi: jika awalnya credit, refund jadi debit
                $direction = ($metadata['ledger_direction'] ?? 'credit') === 'credit' ? 'debit' : 'credit';

                $ledger->record(
                    accountType: $accType,
                    accountId: $accId,
                    amount: $amount,
                    direction: $direction,
                    referenceType: 'refund',
                    referenceId: (string) $txn['reference_no'],
                    description: "Payment refunded for {$txn['reference_no']}: {$reason}",
                    metadata: ['reason' => $reason],
                    allowNegative: true,
                    tenantId: (int) $txn['tenant_id']
                );
            }

            return true;
        }

        return false;
    }
}
