<?php

declare(strict_types=1);

namespace Core\Payment;

class PaymentRequest
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public int $tenantId,
        public string $referenceNo,
        public float $amount,
        public string $currency = 'IDR',
        public ?string $paymentMethod = null,
        public ?string $customerName = null,
        public ?string $customerEmail = null,
        public ?string $customerPhone = null,
        public ?string $description = null,
        public array $metadata = []
    ) {}
}
