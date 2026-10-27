<?php

declare(strict_types=1);

namespace Core\Payment;

class ManualTransferDriver implements PaymentDriverInterface
{
    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_holder' => 'PT AIO PLATFORM INDONESIA',
        ], $config);
    }

    public function charge(PaymentRequest $request): PaymentResponse
    {
        $gwRef = 'manual_' . $request->referenceNo;

        return new PaymentResponse(
            isSuccess: true,
            referenceNo: $request->referenceNo,
            status: 'pending',
            gatewayReference: $gwRef,
            checkoutUrl: null,
            qrString: null,
            errorMessage: null,
            metadata: [
                'type' => 'manual_transfer',
                'bank_name' => $this->config['bank_name'],
                'account_number' => $this->config['account_number'],
                'account_holder' => $this->config['account_holder'],
                'instructions' => "Silakan transfer tepat sejumlah Rp " . number_format($request->amount, 0, ',', '.') . " ke rekening {$this->config['bank_name']} {$this->config['account_number']}.",
            ]
        );
    }

    public function verifyWebhook(array $payload, array $headers = []): WebhookResult
    {
        $ref = $payload['reference_no'] ?? null;
        if (!$ref || !is_string($ref)) {
            return new WebhookResult(
                isValid: false,
                referenceNo: '',
                status: 'failed',
                errorMessage: 'Missing reference_no for manual transfer confirmation'
            );
        }

        $status = (string) ($payload['status'] ?? 'paid');
        $amount = (float) ($payload['amount'] ?? 0.0);
        $gwRef = 'manual_' . $ref;

        return new WebhookResult(
            isValid: true,
            referenceNo: $ref,
            status: $status,
            gatewayReference: $gwRef,
            amount: $amount,
            paymentMethod: 'bank_transfer',
            rawPayload: $payload
        );
    }

    public function checkStatus(string $gatewayReference): string
    {
        return 'pending';
    }

    public function refund(string $gatewayReference, float $amount, string $reason = ''): bool
    {
        return true;
    }
}
