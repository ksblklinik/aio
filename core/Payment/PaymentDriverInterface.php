<?php

declare(strict_types=1);

namespace Core\Payment;

interface PaymentDriverInterface
{
    /**
     * Inisiasi tagihan pembayaran ke gateway
     */
    public function charge(PaymentRequest $request): PaymentResponse;

    /**
     * Verifikasi callback webhook dari gateway
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $headers
     */
    public function verifyWebhook(array $payload, array $headers = []): WebhookResult;

    /**
     * Periksa status pembayaran dari gateway
     */
    public function checkStatus(string $gatewayReference): string;

    /**
     * Lakukan refund transaksi
     */
    public function refund(string $gatewayReference, float $amount, string $reason = ''): bool;
}
