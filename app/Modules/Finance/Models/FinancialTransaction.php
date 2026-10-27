<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

class FinancialTransaction
{
    public function __construct(
        public ?int $id = null,
        public int $tenantId = 0,
        public string $transactionNumber = '',
        public string $type = 'outflow', // 'inflow', 'outflow', 'transfer'
        public int $sourceAccountId = 0,
        public ?int $targetAccountId = null,
        public ?int $categoryId = null,
        public ?int $contactId = null,
        public float $amount = 0.0,
        public string $transactionDate = '',
        public string $status = 'completed', // 'draft', 'pending_approval', 'completed', 'cancelled'
        public ?string $referenceNumber = null,
        public string $description = '',
        public ?string $receiptFilePath = null,
        public ?int $approvedBy = null,
        public ?string $approvedAt = null,
        public ?int $createdBy = null,
        public ?string $createdAt = null,
        public ?string $updatedAt = null
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            tenantId: (int) ($row['tenant_id'] ?? 0),
            transactionNumber: (string) ($row['transaction_number'] ?? ''),
            type: (string) ($row['type'] ?? 'outflow'),
            sourceAccountId: (int) ($row['source_account_id'] ?? 0),
            targetAccountId: isset($row['target_account_id']) && $row['target_account_id'] !== null ? (int) $row['target_account_id'] : null,
            categoryId: isset($row['category_id']) && $row['category_id'] !== null ? (int) $row['category_id'] : null,
            contactId: isset($row['contact_id']) && $row['contact_id'] !== null ? (int) $row['contact_id'] : null,
            amount: (float) ($row['amount'] ?? 0.0),
            transactionDate: (string) ($row['transaction_date'] ?? date('Y-m-d')),
            status: (string) ($row['status'] ?? 'completed'),
            referenceNumber: isset($row['reference_number']) ? (string) $row['reference_number'] : null,
            description: (string) ($row['description'] ?? ''),
            receiptFilePath: isset($row['receipt_file_path']) ? (string) $row['receipt_file_path'] : null,
            approvedBy: isset($row['approved_by']) && $row['approved_by'] !== null ? (int) $row['approved_by'] : null,
            approvedAt: isset($row['approved_at']) ? (string) $row['approved_at'] : null,
            createdBy: isset($row['created_by']) && $row['created_by'] !== null ? (int) $row['created_by'] : null,
            createdAt: isset($row['created_at']) ? (string) $row['created_at'] : null,
            updatedAt: isset($row['updated_at']) ? (string) $row['updated_at'] : null
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'transaction_number' => $this->transactionNumber,
            'type' => $this->type,
            'source_account_id' => $this->sourceAccountId,
            'target_account_id' => $this->targetAccountId,
            'category_id' => $this->categoryId,
            'contact_id' => $this->contactId,
            'amount' => $this->amount,
            'transaction_date' => $this->transactionDate,
            'status' => $this->status,
            'reference_number' => $this->referenceNumber,
            'description' => $this->description,
            'receipt_file_path' => $this->receiptFilePath,
            'approved_by' => $this->approvedBy,
            'approved_at' => $this->approvedAt,
            'created_by' => $this->createdBy,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
