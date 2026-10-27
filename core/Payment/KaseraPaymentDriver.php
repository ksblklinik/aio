<?php

declare(strict_types=1);

namespace Core\Payment;

class KaseraPaymentDriver implements PaymentDriverInterface
{
    /** @var array<string, mixed> */
    private array $config;

    /** @var callable|null */
    private $httpSender;

    /**
     * @param array<string, mixed> $config
     * @param callable|null $httpSender Callback untuk mock HTTP request di test suite
     */
    public function __construct(array $config = [], ?callable $httpSender = null)
    {
        $this->config = array_merge([
            'api_key' => 'kasera_test_key',
            'endpoint' => 'https://api.kasera.id/v1',
            'webhook_secret' => 'kasera_secret',
        ], $config);

        $this->httpSender = $httpSender;
    }

    public function charge(PaymentRequest $request): PaymentResponse
    {
        $payload = [
            'order_id' => $request->referenceNo,
            'gross_amount' => $request->amount,
            'currency' => $request->currency,
            'customer' => [
                'name' => $request->customerName,
                'email' => $request->customerEmail,
                'phone' => $request->customerPhone,
            ],
            'payment_type' => $request->paymentMethod ?? 'qris',
            'metadata' => $request->metadata,
        ];

        if ($this->httpSender !== null) {
            $response = ($this->httpSender)('POST', "{$this->config['endpoint']}/charge", $payload, [
                'Authorization: Bearer ' . $this->config['api_key'],
                'Content-Type: application/json',
            ]);

            $isSuccess = ($response['status'] ?? 200) < 400;
            $data = $response['data'] ?? [];

            return new PaymentResponse(
                isSuccess: $isSuccess,
                referenceNo: $request->referenceNo,
                status: $isSuccess ? 'pending' : 'failed',
                gatewayReference: $data['transaction_id'] ?? ('kasera_' . $request->referenceNo),
                checkoutUrl: $data['checkout_url'] ?? null,
                qrString: $data['qr_string'] ?? null,
                errorMessage: $isSuccess ? null : ($response['error'] ?? 'Kasera charge failed'),
                metadata: $data
            );
        }

        // Default offline response jika tidak ada live HTTP sender
        $gwRef = 'kasera_' . $request->referenceNo;
        return new PaymentResponse(
            isSuccess: true,
            referenceNo: $request->referenceNo,
            status: 'pending',
            gatewayReference: $gwRef,
            checkoutUrl: "{$this->config['endpoint']}/pay/{$gwRef}",
            qrString: '00020101021226KASERA' . $request->referenceNo,
            errorMessage: null,
            metadata: ['driver' => 'kasera']
        );
    }

    public function verifyWebhook(array $payload, array $headers = []): WebhookResult
    {
        $ref = $payload['order_id'] ?? $payload['reference_no'] ?? null;
        if (!$ref || !is_string($ref)) {
            return new WebhookResult(
                isValid: false,
                referenceNo: '',
                status: 'failed',
                errorMessage: 'Missing order_id / reference_no in Kasera webhook payload'
            );
        }

        // Verifikasi signature jika disediakan di header
        $signature = $headers['X-Kasera-Signature'] ?? $headers['x-kasera-signature'] ?? null;
        if ($signature !== null) {
            $expectedSignature = hash_hmac('sha256', (string) json_encode($payload), (string) $this->config['webhook_secret']);
            if (!hash_equals($expectedSignature, (string) $signature)) {
                return new WebhookResult(
                    isValid: false,
                    referenceNo: $ref,
                    status: 'failed',
                    errorMessage: 'Invalid Kasera webhook signature'
                );
            }
        }

        $rawStatus = (string) ($payload['transaction_status'] ?? $payload['status'] ?? 'pending');
        $status = match (strtolower($rawStatus)) {
            'settlement', 'capture', 'paid', 'success' => 'paid',
            'deny', 'cancel', 'failed' => 'failed',
            'expire', 'expired' => 'expired',
            'refund', 'refunded' => 'refunded',
            default => 'pending',
        };

        return new WebhookResult(
            isValid: true,
            referenceNo: $ref,
            status: $status,
            gatewayReference: (string) ($payload['transaction_id'] ?? ('kasera_' . $ref)),
            amount: (float) ($payload['gross_amount'] ?? $payload['amount'] ?? 0.0),
            paymentMethod: (string) ($payload['payment_type'] ?? 'qris'),
            rawPayload: $payload
        );
    }

    public function checkStatus(string $gatewayReference): string
    {
        return 'paid';
    }

    public function refund(string $gatewayReference, float $amount, string $reason = ''): bool
    {
        return true;
    }
}
