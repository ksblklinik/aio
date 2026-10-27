<?php

declare(strict_types=1);

namespace Core\Payment;

class MockPaymentDriver implements PaymentDriverInterface
{
    /** @var array<string, string> */
    private array $statusStore = [];

    public function charge(PaymentRequest $request): PaymentResponse
    {
        $gwRef = 'mock_gw_' . $request->referenceNo;
        $this->statusStore[$gwRef] = 'pending';

        return new PaymentResponse(
            isSuccess: true,
            referenceNo: $request->referenceNo,
            status: 'pending',
            gatewayReference: $gwRef,
            checkoutUrl: 'https://checkout.aio.test/mock/' . urlencode($request->referenceNo),
            qrString: '00020101021226' . $request->referenceNo . '5802ID5903AIO',
            errorMessage: null,
            metadata: ['mock' => true, 'driver' => 'mock']
        );
    }

    public function verifyWebhook(array $payload, array $headers = []): WebhookResult
    {
        $ref = $payload['reference_no'] ?? $payload['order_id'] ?? null;
        if (!$ref || !is_string($ref)) {
            return new WebhookResult(
                isValid: false,
                referenceNo: '',
                status: 'failed',
                errorMessage: 'Missing reference_no in webhook payload'
            );
        }

        $status = (string) ($payload['status'] ?? 'paid');
        $amount = (float) ($payload['amount'] ?? 0.0);
        $gwRef = (string) ($payload['gateway_reference'] ?? ('mock_gw_' . $ref));
        $method = isset($payload['payment_method']) ? (string) $payload['payment_method'] : 'mock_qris';

        $this->statusStore[$gwRef] = $status;

        return new WebhookResult(
            isValid: true,
            referenceNo: $ref,
            status: $status,
            gatewayReference: $gwRef,
            amount: $amount,
            paymentMethod: $method,
            rawPayload: $payload
        );
    }

    public function checkStatus(string $gatewayReference): string
    {
        return $this->statusStore[$gatewayReference] ?? 'pending';
    }

    public function refund(string $gatewayReference, float $amount, string $reason = ''): bool
    {
        $this->statusStore[$gatewayReference] = 'refunded';
        return true;
    }

    /**
     * Helper untuk membuat payload webhook simulasi untuk testing
     *
     * @return array<string, mixed>
     */
    public function generateWebhookPayload(string $referenceNo, string $status = 'paid', float $amount = 0.0, string $method = 'qris'): array
    {
        return [
            'reference_no' => $referenceNo,
            'gateway_reference' => 'mock_gw_' . $referenceNo,
            'status' => $status,
            'amount' => $amount,
            'payment_method' => $method,
            'timestamp' => time(),
        ];
    }
}
