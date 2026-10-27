<?php

declare(strict_types=1);

namespace Core\Payment;

class PaymentResponse
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public bool $isSuccess,
        public string $referenceNo,
        public string $status = 'pending',
        public ?string $gatewayReference = null,
        public ?string $checkoutUrl = null,
        public ?string $qrString = null,
        public ?string $errorMessage = null,
        public array $metadata = []
    ) {}
}
