<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

class FinancialAccount
{
    public function __construct(
        public ?int $id = null,
        public int $tenantId = 0,
        public string $code = '',
        public string $name = '',
        public string $type = 'cash', // 'cash', 'bank', 'ewallet'
        public ?string $accountNumber = null, // Plaintext di objek, terenkripsi AES-256-GCM di DB
        public ?string $bankName = null,
        public string $currency = 'IDR',
        public float $initialBalance = 0.0,
        public float $currentBalance = 0.0,
        public bool $isActive = true,
        public ?string $createdAt = null,
        public ?string $updatedAt = null
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $accountNum = $row['account_number'] ?? null;
        if ($accountNum !== null && is_string($accountNum) && $accountNum !== '') {
            try {
                $accountNum = decrypt($accountNum);
            } catch (\Throwable) {
                // Biarkan jika format plaintext legacy atau testing
            }
        }

        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            tenantId: (int) ($row['tenant_id'] ?? 0),
            code: (string) ($row['code'] ?? ''),
            name: (string) ($row['name'] ?? ''),
            type: (string) ($row['type'] ?? 'cash'),
            accountNumber: $accountNum,
            bankName: isset($row['bank_name']) ? (string) $row['bank_name'] : null,
            currency: (string) ($row['currency'] ?? 'IDR'),
            initialBalance: (float) ($row['initial_balance'] ?? 0.0),
            currentBalance: (float) ($row['current_balance'] ?? 0.0),
            isActive: (bool) ($row['is_active'] ?? true),
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
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'account_number' => $this->accountNumber,
            'bank_name' => $this->bankName,
            'currency' => $this->currency,
            'initial_balance' => $this->initialBalance,
            'current_balance' => $this->currentBalance,
            'is_active' => $this->isActive ? 1 : 0,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
