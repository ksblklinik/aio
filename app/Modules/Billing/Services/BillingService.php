<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Contracts\BillingRegistryInterface;
use Core\Database\Connection;
use Core\Database\TenantContext;
use InvalidArgumentException;
use RuntimeException;

class BillingService implements BillingRegistryInterface
{
    public function __construct(
        private readonly Connection $db,
        private readonly TenantContext $tenantContext
    ) {}

    private function getTenantId(): int
    {
        $tenantId = $this->tenantContext->getTenantId();
        if ($tenantId === null) {
            throw new RuntimeException('Tenant context tidak aktif.');
        }

        return (int) $tenantId;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createInvoice(array $data): array
    {
        $tenantId = $this->getTenantId();

        $type = $data['type'] ?? 'invoice';
        $contactId = !empty($data['contact_id']) ? (int) $data['contact_id'] : null;
        $contactName = trim((string) ($data['contact_name'] ?? ''));

        if ($contactName === '' && $contactId !== null && function_exists('contacts')) {
            try {
                $contact = contacts()->find($contactId);
                if ($contact) {
                    $contactName = (string) ($contact->name ?? '');
                }
            } catch (\Throwable) {
                // fallback if not found
            }
        }

        if ($contactName === '') {
            $contactName = $type === 'bill' ? 'Vendor Umum' : 'Pelanggan Umum';
        }

        $items = $data['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            throw new InvalidArgumentException('Invoice wajib memiliki minimal satu item transaksi.');
        }

        // Generate invoice number jika belum disediakan
        $invoiceNumber = $data['invoice_number'] ?? null;
        if (empty($invoiceNumber)) {
            $prefix = $type === 'bill' ? 'BILL' : 'INV';
            $seqKey = $type === 'bill' ? 'bill' : 'invoice';
            if (function_exists('sequence')) {
                $invoiceNumber = sequence($seqKey, $prefix, 5, 'yearly');
            } else {
                $invoiceNumber = sprintf('%s/%s/%05d', $prefix, date('Y'), rand(1, 99999));
            }
        }

        // Hitung total nilai items
        $subtotal = 0.0;
        $discountTotal = 0.0;
        $taxTotal = 0.0;
        $processedItems = [];

        foreach ($items as $item) {
            $qty = (float) ($item['quantity'] ?? 1.0);
            if ($qty <= 0) {
                throw new InvalidArgumentException('Kuantitas item harus lebih dari 0.');
            }

            $itemId = !empty($item['item_id']) ? (int) $item['item_id'] : null;
            $itemName = trim((string) ($item['item_name'] ?? ''));
            $itemType = (string) ($item['item_type'] ?? 'goods');

            // Ambil dari master item jika nama kosong
            if ($itemName === '' && $itemId !== null && function_exists('items')) {
                try {
                    $masterItem = items()->getItem($itemId);
                    if ($masterItem) {
                        $itemName = (string) $masterItem['name'];
                        $itemType = (string) $masterItem['type'];
                        if (!isset($item['unit_price'])) {
                            $item['unit_price'] = $type === 'bill' ? $masterItem['cost_price'] : $masterItem['selling_price'];
                        }
                    }
                } catch (\Throwable) {
                    // ignore
                }
            }

            if ($itemName === '') {
                $itemName = 'Item Transaksi';
            }

            $unitPrice = (float) ($item['unit_price'] ?? 0.0);
            $discount = (float) ($item['discount'] ?? 0.0);
            $taxRate = (float) ($item['tax_rate'] ?? 0.0);

            $baseAmount = $qty * $unitPrice;
            $taxAmount = ($baseAmount - $discount) * ($taxRate / 100);
            $itemSubtotal = ($baseAmount - $discount) + $taxAmount;

            $subtotal += $baseAmount;
            $discountTotal += $discount;
            $taxTotal += $taxAmount;

            $processedItems[] = [
                'item_id' => $itemId,
                'item_name' => $itemName,
                'item_type' => $itemType,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'discount' => $discount,
                'tax_rate' => $taxRate,
                'subtotal' => round($itemSubtotal, 2),
                'room_id' => !empty($item['room_id']) ? (int) $item['room_id'] : null,
                'batch_id' => !empty($item['batch_id']) ? (int) $item['batch_id'] : null,
            ];
        }

        $grandTotal = round(($subtotal - $discountTotal) + $taxTotal, 2);
        $status = (string) ($data['status'] ?? 'draft');
        $issueDate = (string) ($data['issue_date'] ?? date('Y-m-d'));
        $dueDate = !empty($data['due_date']) ? (string) $data['due_date'] : null;
        $currency = (string) ($data['currency'] ?? 'IDR');
        $notes = !empty($data['notes']) ? (string) $data['notes'] : null;
        $createdBy = !empty($data['created_by']) ? (int) $data['created_by'] : null;

        $amountPaid = 0.0;
        $amountDue = $grandTotal;
        $paymentStatus = 'unpaid';

        return $this->db->transaction(function () use (
            $tenantId, $invoiceNumber, $type, $contactId, $contactName, $status,
            $issueDate, $dueDate, $currency, $subtotal, $discountTotal, $taxTotal,
            $grandTotal, $amountPaid, $amountDue, $paymentStatus, $notes, $createdBy, $processedItems
        ) {
            $invoiceId = (int) $this->db->insert('invoices', [
                'tenant_id' => $tenantId,
                'invoice_number' => $invoiceNumber,
                'type' => $type,
                'contact_id' => $contactId,
                'contact_name' => $contactName,
                'status' => $status,
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'currency' => $currency,
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'tax_total' => $taxTotal,
                'grand_total' => $grandTotal,
                'amount_paid' => $amountPaid,
                'amount_due' => $amountDue,
                'payment_status' => $paymentStatus,
                'notes' => $notes,
                'created_by' => $createdBy,
            ]);

            foreach ($processedItems as $pItem) {
                $this->db->insert('invoice_items', [
                    'tenant_id' => $tenantId,
                    'invoice_id' => $invoiceId,
                    'item_id' => $pItem['item_id'],
                    'item_name' => $pItem['item_name'],
                    'item_type' => $pItem['item_type'],
                    'quantity' => $pItem['quantity'],
                    'unit_price' => $pItem['unit_price'],
                    'discount' => $pItem['discount'],
                    'tax_rate' => $pItem['tax_rate'],
                    'subtotal' => $pItem['subtotal'],
                ]);
            }

            // Jika status langsung 'issued', potong stok jika ada item fisik
            if ($status === 'issued') {
                $this->deductItemsStock($invoiceId, $invoiceNumber, $processedItems);
            }

            return $this->getInvoice($invoiceId) ?? throw new RuntimeException('Gagal mengambil data invoice.');
        });
    }

    public function getInvoice(int $id): ?array
    {
        $tenantId = $this->getTenantId();

        $invoice = $this->db->selectOne(
            "SELECT * FROM invoices WHERE id = ? AND tenant_id = ?",
            [$id, $tenantId]
        );

        if (!$invoice) {
            return null;
        }

        $items = $this->db->select(
            "SELECT * FROM invoice_items WHERE invoice_id = ? AND tenant_id = ? ORDER BY id ASC",
            [$id, $tenantId]
        );

        $payments = $this->db->select(
            "SELECT * FROM invoice_payments WHERE invoice_id = ? AND tenant_id = ? ORDER BY id ASC",
            [$id, $tenantId]
        );

        $invoice['items'] = $items;
        $invoice['payments'] = $payments;

        return $invoice;
    }

    public function getInvoiceByNumber(string $invoiceNumber): ?array
    {
        $tenantId = $this->getTenantId();

        $invoice = $this->db->selectOne(
            "SELECT * FROM invoices WHERE invoice_number = ? AND tenant_id = ?",
            [$invoiceNumber, $tenantId]
        );

        if (!$invoice) {
            return null;
        }

        return $this->getInvoice((int) $invoice['id']);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateInvoice(int $id, array $data): array
    {
        $invoice = $this->getInvoice($id);
        if (!$invoice) {
            throw new RuntimeException("Invoice dengan ID {$id} tidak ditemukan.");
        }

        if ($invoice['status'] !== 'draft') {
            throw new RuntimeException('Hanya invoice berstatus draft yang dapat diubah.');
        }

        $tenantId = $this->getTenantId();

        $contactId = array_key_exists('contact_id', $data) ? ($data['contact_id'] ? (int) $data['contact_id'] : null) : $invoice['contact_id'];
        $contactName = array_key_exists('contact_name', $data) ? trim((string) $data['contact_name']) : $invoice['contact_name'];
        $notes = array_key_exists('notes', $data) ? (string) $data['notes'] : $invoice['notes'];
        $dueDate = array_key_exists('due_date', $data) ? ($data['due_date'] ? (string) $data['due_date'] : null) : $invoice['due_date'];

        return $this->db->transaction(function () use ($id, $tenantId, $data, $contactId, $contactName, $notes, $dueDate) {
            if (isset($data['items']) && is_array($data['items'])) {
                // Hapus item lama dan kalkulasi ulang
                $this->db->statement("DELETE FROM invoice_items WHERE invoice_id = ? AND tenant_id = ?", [$id, $tenantId]);

                $subtotal = 0.0;
                $discountTotal = 0.0;
                $taxTotal = 0.0;

                foreach ($data['items'] as $item) {
                    $qty = (float) ($item['quantity'] ?? 1.0);
                    $itemId = !empty($item['item_id']) ? (int) $item['item_id'] : null;
                    $itemName = trim((string) ($item['item_name'] ?? 'Item'));
                    $itemType = (string) ($item['item_type'] ?? 'goods');
                    $unitPrice = (float) ($item['unit_price'] ?? 0.0);
                    $discount = (float) ($item['discount'] ?? 0.0);
                    $taxRate = (float) ($item['tax_rate'] ?? 0.0);

                    $baseAmount = $qty * $unitPrice;
                    $taxAmount = ($baseAmount - $discount) * ($taxRate / 100);
                    $itemSubtotal = ($baseAmount - $discount) + $taxAmount;

                    $subtotal += $baseAmount;
                    $discountTotal += $discount;
                    $taxTotal += $taxAmount;

                    $this->db->insert('invoice_items', [
                        'tenant_id' => $tenantId,
                        'invoice_id' => $id,
                        'item_id' => $itemId,
                        'item_name' => $itemName,
                        'item_type' => $itemType,
                        'quantity' => $qty,
                        'unit_price' => $unitPrice,
                        'discount' => $discount,
                        'tax_rate' => $taxRate,
                        'subtotal' => round($itemSubtotal, 2),
                    ]);
                }

                $grandTotal = round(($subtotal - $discountTotal) + $taxTotal, 2);

                $this->db->statement(
                    "UPDATE invoices SET
                        contact_id = ?, contact_name = ?, notes = ?, due_date = ?,
                        subtotal = ?, discount_total = ?, tax_total = ?, grand_total = ?,
                        amount_due = ?, updated_at = datetime('now')
                    WHERE id = ? AND tenant_id = ?",
                    [
                        $contactId, $contactName, $notes, $dueDate,
                        $subtotal, $discountTotal, $taxTotal, $grandTotal,
                        $grandTotal, $id, $tenantId,
                    ]
                );
            } else {
                $this->db->statement(
                    "UPDATE invoices SET
                        contact_id = ?, contact_name = ?, notes = ?, due_date = ?,
                        updated_at = datetime('now')
                    WHERE id = ? AND tenant_id = ?",
                    [$contactId, $contactName, $notes, $dueDate, $id, $tenantId]
                );
            }

            return $this->getInvoice($id) ?? throw new RuntimeException('Gagal memuat invoice.');
        });
    }

    public function issueInvoice(int $id, bool $deductInventory = true): array
    {
        $invoice = $this->getInvoice($id);
        if (!$invoice) {
            throw new RuntimeException("Invoice dengan ID {$id} tidak ditemukan.");
        }

        if ($invoice['status'] !== 'draft') {
            throw new RuntimeException('Hanya invoice berstatus draft yang dapat diterbitkan.');
        }

        $tenantId = $this->getTenantId();

        return $this->db->transaction(function () use ($id, $tenantId, $invoice, $deductInventory) {
            if ($deductInventory) {
                $this->deductItemsStock($id, $invoice['invoice_number'], $invoice['items']);
            }

            $this->db->statement(
                "UPDATE invoices SET status = 'issued', updated_at = datetime('now') WHERE id = ? AND tenant_id = ?",
                [$id, $tenantId]
            );

            return $this->getInvoice($id) ?? throw new RuntimeException('Gagal memuat invoice.');
        });
    }

    /**
     * @param array<string, mixed> $paymentData
     * @return array<string, mixed>
     */
    public function recordPayment(int $invoiceId, array $paymentData): array
    {
        $invoice = $this->getInvoice($invoiceId);
        if (!$invoice) {
            throw new RuntimeException("Invoice dengan ID {$invoiceId} tidak ditemukan.");
        }

        if ($invoice['status'] === 'cancelled') {
            throw new RuntimeException('Tidak dapat menerima pembayaran untuk invoice yang telah dibatalkan.');
        }

        if ($invoice['payment_status'] === 'paid') {
            throw new RuntimeException('Invoice ini sudah lunas.');
        }

        $tenantId = $this->getTenantId();
        $amount = (float) ($paymentData['amount'] ?? 0.0);

        if ($amount <= 0.0) {
            throw new InvalidArgumentException('Nominal pembayaran harus lebih dari 0.');
        }

        $due = (float) $invoice['amount_due'];
        if ($amount > round($due, 2) + 0.001) {
            throw new InvalidArgumentException(sprintf('Nominal pembayaran (%.2f) tidak boleh melebihi sisa tagihan (%.2f).', $amount, $due));
        }

        $paymentNumber = $paymentData['payment_number'] ?? null;
        if (empty($paymentNumber)) {
            if (function_exists('sequence')) {
                $paymentNumber = sequence('payment_receipt', 'RCP', 5, 'yearly');
            } else {
                $paymentNumber = sprintf('RCP/%s/%05d', date('Y'), rand(1, 99999));
            }
        }

        $accountId = !empty($paymentData['account_id']) ? (int) $paymentData['account_id'] : null;
        $paymentMethod = (string) ($paymentData['payment_method'] ?? 'cash');
        $paymentDate = (string) ($paymentData['payment_date'] ?? date('Y-m-d'));
        $referenceNote = !empty($paymentData['reference_note']) ? (string) $paymentData['reference_note'] : null;
        $createdBy = !empty($paymentData['created_by']) ? (int) $paymentData['created_by'] : null;

        return $this->db->transaction(function () use (
            $tenantId, $invoiceId, $paymentNumber, $accountId,
            $paymentMethod, $amount, $paymentDate, $referenceNote, $createdBy
        ) {
            // Re-query the latest state of the invoice inside the transaction to prevent race conditions
            $currentInvoice = $this->db->selectOne(
                "SELECT * FROM invoices WHERE id = ? AND tenant_id = ?",
                [$invoiceId, $tenantId]
            );
            if (!$currentInvoice) {
                throw new RuntimeException("Invoice dengan ID {$invoiceId} tidak ditemukan.");
            }
            if ($currentInvoice['status'] === 'cancelled') {
                throw new RuntimeException('Tidak dapat menerima pembayaran untuk invoice yang telah dibatalkan.');
            }
            if ($currentInvoice['payment_status'] === 'paid') {
                throw new RuntimeException('Invoice ini sudah lunas.');
            }

            $currentDue = (float) $currentInvoice['amount_due'];
            if ($amount > round($currentDue, 2) + 0.001) {
                throw new InvalidArgumentException(sprintf('Nominal pembayaran (%.2f) tidak boleh melebihi sisa tagihan (%.2f).', $amount, $currentDue));
            }

            $transactionId = null;

            // Integrasi Finance: Catat mutasi kas masuk / keluar jika account_id disediakan
            if ($accountId !== null && function_exists('finance')) {
                try {
                    $flowDesc = sprintf(
                        'Pembayaran %s untuk %s (%s)',
                        $paymentNumber,
                        $currentInvoice['invoice_number'],
                        $currentInvoice['contact_name']
                    );

                    $categories = finance()->listCategories($currentInvoice['type'] === 'bill' ? 'expense' : 'income');
                    $catId = !empty($categories) ? (int) $categories[0]['id'] : 1;

                    if ($currentInvoice['type'] === 'bill') {
                        $transactionId = finance()->recordOutflow(
                            $accountId,
                            $amount,
                            $catId,
                            $currentInvoice['contact_id'] ? (int) $currentInvoice['contact_id'] : null,
                            $flowDesc,
                            $currentInvoice['invoice_number'],
                            null,
                            false,
                            $paymentDate
                        );
                    } else {
                        $transactionId = finance()->recordInflow(
                            $accountId,
                            $amount,
                            $catId,
                            $currentInvoice['contact_id'] ? (int) $currentInvoice['contact_id'] : null,
                            $flowDesc,
                            $currentInvoice['invoice_number'],
                            null,
                            $paymentDate
                        );
                    }
                } catch (\Throwable $fe) {
                    throw new RuntimeException('Gagal membukukan mutasi keuangan: ' . $fe->getMessage(), 0, $fe);
                }
            }

            // Simpan record invoice_payments
            $this->db->insert('invoice_payments', [
                'tenant_id' => $tenantId,
                'invoice_id' => $invoiceId,
                'payment_number' => $paymentNumber,
                'account_id' => $accountId,
                'transaction_id' => $transactionId,
                'payment_method' => $paymentMethod,
                'amount' => $amount,
                'payment_date' => $paymentDate,
                'reference_note' => $referenceNote,
                'created_by' => $createdBy,
            ]);

            // Perbarui akumulasi invoice
            $newPaid = round((float) $currentInvoice['amount_paid'] + $amount, 2);
            $newDue = max(0.0, round((float) $currentInvoice['grand_total'] - $newPaid, 2));
            $isFullyPaid = $newDue <= 0.001;

            $newPaymentStatus = $isFullyPaid ? 'paid' : 'partially_paid';
            $newStatus = $isFullyPaid ? 'paid' : 'partially_paid';

            $affected = $this->db->affectingStatement(
                "UPDATE invoices SET
                    amount_paid = ?, amount_due = ?, payment_status = ?, status = ?,
                    updated_at = datetime('now')
                WHERE id = ? AND tenant_id = ? AND amount_due >= ?",
                [$newPaid, $newDue, $newPaymentStatus, $newStatus, $invoiceId, $tenantId, round($amount - 0.001, 2)]
            );

            if ($affected !== 1) {
                throw new RuntimeException("Gagal mencatat pembayaran: sisa tagihan invoice telah berubah oleh transaksi lain.");
            }

            return $this->getInvoice($invoiceId) ?? throw new RuntimeException('Gagal memuat invoice.');
        });
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function quickCheckout(array $data): array
    {
        // 1. Tentukan status awal issued
        $data['status'] = 'issued';
        $paymentAmount = (float) ($data['payment_amount'] ?? 0.0);
        $accountId = !empty($data['account_id']) ? (int) $data['account_id'] : null;
        $paymentMethod = (string) ($data['payment_method'] ?? 'cash');

        return $this->db->transaction(function () use ($data, $paymentAmount, $accountId, $paymentMethod) {
            // 2. Buat invoice (termasuk potong stok)
            $invoice = $this->createInvoice($data);
            $grandTotal = (float) $invoice['grand_total'];

            if ($paymentAmount < $grandTotal) {
                throw new InvalidArgumentException(sprintf(
                    'Nominal bayar kasir (%.2f) kurang dari grand total (%.2f).',
                    $paymentAmount,
                    $grandTotal
                ));
            }

            // 3. Rekam pelunasan kasir
            $paymentResult = $this->recordPayment((int) $invoice['id'], [
                'amount' => $grandTotal,
                'account_id' => $accountId,
                'payment_method' => $paymentMethod,
                'payment_date' => $data['issue_date'] ?? date('Y-m-d'),
                'reference_note' => 'Quick POS Checkout',
            ]);

            $change = round($paymentAmount - $grandTotal, 2);
            $paymentResult['tendered_amount'] = $paymentAmount;
            $paymentResult['change'] = $change;

            return $paymentResult;
        });
    }

    public function cancelInvoice(int $id, ?string $reason = null, bool $revertStock = true): array
    {
        $invoice = $this->getInvoice($id);
        if (!$invoice) {
            throw new RuntimeException("Invoice dengan ID {$id} tidak ditemukan.");
        }

        if ($invoice['status'] === 'cancelled') {
            throw new RuntimeException('Invoice sudah dibatalkan sebelumnya.');
        }

        $tenantId = $this->getTenantId();

        return $this->db->transaction(function () use ($id, $tenantId, $invoice, $reason, $revertStock) {
            // Kembalikan stok fisik jika status sebelumnya bukan draft
            if ($revertStock && in_array($invoice['status'], ['issued', 'partially_paid', 'paid'], true)) {
                $this->revertItemsStock($id, $invoice['invoice_number'], $invoice['items']);
            }

            $cancelNote = $invoice['notes'] ? $invoice['notes'] . "\n" : '';
            $cancelNote .= '[Dibatalkan: ' . ($reason ?: 'Pembatalan transaksi') . ' pada ' . date('Y-m-d H:i:s') . ']';

            $this->db->statement(
                "UPDATE invoices SET
                    status = 'cancelled', notes = ?, updated_at = datetime('now')
                WHERE id = ? AND tenant_id = ?",
                [$cancelNote, $id, $tenantId]
            );

            // Batalkan alokasi fee/jasa medis (accrued) terkait via kontrak Tariff atau fallback lokal
            try {
                if (function_exists('tariff') && app()->has(\App\Modules\Tariff\Contracts\TariffRegistryInterface::class)) {
                    tariff()->cancelInvoiceSplits($id);
                } else {
                    $this->db->statement(
                        "UPDATE tariff_fee_allocations 
                         SET status = 'cancelled', updated_at = datetime('now') 
                         WHERE invoice_id = ? AND tenant_id = ? AND status = 'accrued'",
                        [$id, $tenantId]
                    );
                }
            } catch (\Throwable) {
                // Modul tariff mungkin belum aktif atau belum dimigrasi di beberapa skenario
            }

            return $this->getInvoice($id) ?? throw new RuntimeException('Gagal memuat invoice.');
        });
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listInvoices(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $tenantId = $this->getTenantId();

        $sql = "SELECT * FROM invoices WHERE tenant_id = ?";
        $params = [$tenantId];

        if (!empty($filters['type'])) {
            $sql .= " AND type = ?";
            $params[] = (string) $filters['type'];
        }

        if (!empty($filters['status'])) {
            $sql .= " AND status = ?";
            $params[] = (string) $filters['status'];
        }

        if (!empty($filters['payment_status'])) {
            $sql .= " AND payment_status = ?";
            $params[] = (string) $filters['payment_status'];
        }

        if (!empty($filters['contact_id'])) {
            $sql .= " AND contact_id = ?";
            $params[] = (int) $filters['contact_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (invoice_number LIKE ? OR contact_name LIKE ?)";
            $search = '%' . trim((string) $filters['search']) . '%';
            $params[] = $search;
            $params[] = $search;
        }

        if (!empty($filters['start_date'])) {
            $sql .= " AND issue_date >= ?";
            $params[] = (string) $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $sql .= " AND issue_date <= ?";
            $params[] = (string) $filters['end_date'];
        }

        $sql .= " ORDER BY id DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->select($sql, $params);
    }

    /**
     * @return array<string, float>
     */
    public function getBillingSummary(): array
    {
        $tenantId = $this->getTenantId();

        $row = $this->db->selectOne(
            "SELECT
                COALESCE(SUM(CASE WHEN status != 'cancelled' THEN grand_total ELSE 0 END), 0) as total_billed,
                COALESCE(SUM(CASE WHEN status != 'cancelled' THEN amount_paid ELSE 0 END), 0) as total_received,
                COALESCE(SUM(CASE WHEN status != 'cancelled' THEN amount_due ELSE 0 END), 0) as total_outstanding,
                COUNT(CASE WHEN status != 'cancelled' THEN 1 END) as count_invoices,
                COUNT(CASE WHEN status != 'cancelled' AND payment_status = 'paid' THEN 1 END) as count_paid,
                COUNT(CASE WHEN status != 'cancelled' AND payment_status = 'unpaid' THEN 1 END) as count_unpaid
            FROM invoices
            WHERE tenant_id = ?",
            [$tenantId]
        );

        return [
            'total_billed' => (float) ($row['total_billed'] ?? 0.0),
            'total_received' => (float) ($row['total_received'] ?? 0.0),
            'total_outstanding' => (float) ($row['total_outstanding'] ?? 0.0),
            'count_invoices' => (float) ($row['count_invoices'] ?? 0.0),
            'count_paid' => (float) ($row['count_paid'] ?? 0.0),
            'count_unpaid' => (float) ($row['count_unpaid'] ?? 0.0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function generateReceipt(int $invoiceId): array
    {
        $invoice = $this->getInvoice($invoiceId);
        if (!$invoice) {
            throw new RuntimeException("Invoice dengan ID {$invoiceId} tidak ditemukan.");
        }

        return [
            'invoice_id' => $invoice['id'],
            'invoice_number' => $invoice['invoice_number'],
            'type' => $invoice['type'],
            'contact_name' => $invoice['contact_name'],
            'issue_date' => $invoice['issue_date'],
            'currency' => $invoice['currency'],
            'subtotal' => (float) $invoice['subtotal'],
            'discount_total' => (float) $invoice['discount_total'],
            'tax_total' => (float) $invoice['tax_total'],
            'grand_total' => (float) $invoice['grand_total'],
            'amount_paid' => (float) $invoice['amount_paid'],
            'amount_due' => (float) $invoice['amount_due'],
            'payment_status' => $invoice['payment_status'],
            'items' => array_map(fn($i) => [
                'name' => $i['item_name'],
                'type' => $i['item_type'],
                'qty' => (float) $i['quantity'],
                'price' => (float) $i['unit_price'],
                'subtotal' => (float) $i['subtotal'],
            ], $invoice['items']),
            'payments' => array_map(fn($p) => [
                'payment_number' => $p['payment_number'],
                'method' => $p['payment_method'],
                'amount' => (float) $p['amount'],
                'date' => $p['payment_date'],
            ], $invoice['payments']),
            'printed_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function deductItemsStock(int $invoiceId, string $invoiceNumber, array $items): void
    {
        if (!function_exists('inventory')) {
            return;
        }

        // Pre-check ketersediaan stok untuk semua item bertipe goods
        foreach ($items as $item) {
            $itemId = !empty($item['item_id']) ? (int) $item['item_id'] : null;
            $itemType = (string) ($item['item_type'] ?? 'goods');
            $qty = (float) ($item['quantity'] ?? 1.0);
            $roomId = !empty($item['room_id']) ? (int) $item['room_id'] : null;

            if ($itemId !== null && $itemType === 'goods') {
                $isAvailable = inventory()->checkAvailability($itemId, $qty, $roomId);
                if (!$isAvailable) {
                    throw new InvalidArgumentException(sprintf(
                        "Stok tidak mencukupi untuk item '%s' (dibutuhkan %.2f%s).",
                        $item['item_name'],
                        $qty,
                        $roomId ? " di ruangan/gudang ID $roomId" : ""
                    ));
                }
            }
        }

        // Lakukan pengurangan stok mutasi 'out'
        foreach ($items as $item) {
            $itemId = !empty($item['item_id']) ? (int) $item['item_id'] : null;
            $itemType = (string) ($item['item_type'] ?? 'goods');
            $qty = (float) ($item['quantity'] ?? 1.0);
            $roomId = !empty($item['room_id']) ? (int) $item['room_id'] : null;
            $batchId = !empty($item['batch_id']) ? (int) $item['batch_id'] : null;

            if ($itemId !== null && $itemType === 'goods') {
                if ($roomId !== null && $batchId === null) {
                    $batches = inventory()->listBatches($itemId, 'active');
                    if (!empty($batches)) {
                        inventory()->deductStockFefo(
                            $itemId,
                            $qty,
                            $roomId,
                            sprintf('Pengurangan stok penjualan Faktur %s', $invoiceNumber),
                            'invoice',
                            (string) $invoiceId
                        );
                        continue;
                    }
                }

                inventory()->recordStockMovement(
                    $itemId,
                    $qty,
                    'out',
                    sprintf('Pengurangan stok penjualan Faktur %s', $invoiceNumber),
                    'invoice',
                    (string) $invoiceId,
                    null,
                    null,
                    $roomId,
                    $batchId
                );
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function revertItemsStock(int $invoiceId, string $invoiceNumber, array $items): void
    {
        if (!function_exists('inventory')) {
            return;
        }

        foreach ($items as $item) {
            $itemId = !empty($item['item_id']) ? (int) $item['item_id'] : null;
            $itemType = (string) ($item['item_type'] ?? 'goods');
            $qty = (float) ($item['quantity'] ?? 1.0);
            $roomId = !empty($item['room_id']) ? (int) $item['room_id'] : null;
            $batchId = !empty($item['batch_id']) ? (int) $item['batch_id'] : null;

            if ($itemId !== null && $itemType === 'goods') {
                inventory()->recordStockMovement(
                    $itemId,
                    $qty,
                    'in',
                    sprintf('Pengembalian stok pembatalan Faktur %s', $invoiceNumber),
                    'invoice_cancel',
                    (string) $invoiceId,
                    null,
                    null,
                    $roomId,
                    $batchId
                );
            }
        }
    }
}
