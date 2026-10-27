<?php

declare(strict_types=1);

namespace Core\Payment;

class WebhookResult
{
    /**
     * @param array<string, mixed> $rawPayload
     */
    public function __construct(
        public bool $isValid,
        public string $referenceNo,
        public string $status, // paid, pending, failed, expired, refunded
        public ?string $gatewayReference = null,
        public float $amount = 0.0,
        public ?string $paymentMethod = null,
        public array $rawPayload = [],
        public ?string $errorMessage = null
    ) {}
}
