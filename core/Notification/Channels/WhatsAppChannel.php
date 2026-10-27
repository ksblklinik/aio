<?php

declare(strict_types=1);

namespace Core\Notification\Channels;

class WhatsAppChannel implements NotificationChannelInterface
{
    /** @var callable|null */
    protected $httpSender;
    protected ?string $endpointUrl;
    protected ?string $apiKey;

    public function __construct(?string $endpointUrl = null, ?string $apiKey = null, ?callable $httpSender = null)
    {
        $this->endpointUrl = $endpointUrl;
        $this->apiKey = $apiKey;
        $this->httpSender = $httpSender;
    }

    public function setHttpSender(callable $sender): self
    {
        $this->httpSender = $sender;
        return $this;
    }

    public function send(
        int|string $tenantId,
        mixed $recipient,
        string $title,
        string $message,
        array $options = []
    ): bool {
        $phone = null;
        if (is_string($recipient) && !filter_var($recipient, FILTER_VALIDATE_EMAIL) && preg_match('/^[0-9+]+$/', $recipient)) {
            $phone = $recipient;
        } elseif (is_object($recipient) && !empty($recipient->phone)) {
            $phone = (string) $recipient->phone;
        } elseif (!empty($options['phone'])) {
            $phone = (string) $options['phone'];
        }

        if ($phone === null) {
            return false;
        }

        // Standardize phone number format (replace leading 08 with 628)
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($cleanPhone, '08')) {
            $cleanPhone = '628' . substr($cleanPhone, 2);
        }

        $formattedText = "*{$title}*\n\n{$message}";
        if (!empty($options['action_url'])) {
            $formattedText .= "\n\nTautan: " . $options['action_url'];
        }

        $payload = [
            'tenant_id' => $tenantId,
            'phone' => $cleanPhone,
            'message' => $formattedText,
        ];

        // 1. Jika ada custom mock/http sender
        if ($this->httpSender !== null) {
            return (bool) ($this->httpSender)($payload, $this->endpointUrl, $this->apiKey);
        }

        // 2. Jika tidak ada endpoint URL terkonfigurasi (misal di local dev), log dan anggap berhasil
        if (empty($this->endpointUrl)) {
            return true;
        }

        // 3. Native HTTP POST
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . ($this->apiKey ?? ''),
                ],
                'content' => json_encode($payload),
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $res = @file_get_contents($this->endpointUrl, false, $context);
        return $res !== false;
    }
}
