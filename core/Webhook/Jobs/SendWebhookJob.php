<?php

declare(strict_types=1);

namespace Core\Webhook\Jobs;

use Core\Container;
use Core\Database\Connection;
use Core\Queue\JobInterface;
use Core\Webhook\WebhookDispatcher;
use Exception;
use Throwable;

class SendWebhookJob implements JobInterface
{
    /**
     * @var (callable(string, array<string, string>, string): array{status: int, body: string})|null
     */
    public static $httpSender = null;

    public function handle(array $data, Container $container): void
    {
        $webhookId = (int) ($data['webhook_id'] ?? 0);
        $tenantId = (int) ($data['tenant_id'] ?? 0);
        $url = (string) ($data['url'] ?? '');
        $secret = (string) ($data['secret'] ?? '');
        $event = (string) ($data['event'] ?? 'ping');
        $payload = (array) ($data['payload'] ?? []);

        /** @var Connection $db */
        $db = $container->get(Connection::class);

        /** @var WebhookDispatcher $dispatcher */
        $dispatcher = $container->get(WebhookDispatcher::class);

        $now = time();
        $envelope = [
            'id' => 'evt_' . bin2hex(random_bytes(12)),
            'event' => $event,
            'timestamp' => $now,
            'tenant_id' => $tenantId,
            'data' => $payload,
        ];

        $jsonPayload = json_encode($envelope, JSON_UNESCAPED_SLASHES);
        $signature = $dispatcher->calculateSignature($jsonPayload, $now, $secret);

        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'aio-Webhook-Dispatcher/1.0',
            'X-Aio-Event' => $event,
            'X-Aio-Timestamp' => (string) $now,
            'X-Aio-Signature' => 'sha256=' . $signature,
        ];

        $startTime = microtime(true);
        $responseCode = 0;
        $responseBody = '';
        $deliveryStatus = 'pending';

        try {
            if (self::$httpSender !== null) {
                $response = (self::$httpSender)($url, $headers, $jsonPayload);
                $responseCode = (int) ($response['status'] ?? 0);
                $responseBody = (string) ($response['body'] ?? '');
            } else {
                $response = $this->sendHttpRequest($url, $headers, $jsonPayload);
                $responseCode = $response['status'];
                $responseBody = $response['body'];
            }

            $durationMs = (int) round((microtime(true) - $startTime) * 1000);
            $isSuccess = $responseCode >= 200 && $responseCode < 300;
            $deliveryStatus = $isSuccess ? 'success' : 'failed';

            $db->insert('webhook_deliveries', [
                'tenant_id' => $tenantId,
                'webhook_id' => $webhookId,
                'event' => $event,
                'payload' => $jsonPayload,
                'response_code' => $responseCode,
                'response_body' => substr($responseBody, 0, 1000),
                'duration_ms' => $durationMs,
                'status' => $deliveryStatus,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            if (!$isSuccess) {
                throw new Exception("Webhook delivery failed with HTTP status {$responseCode}");
            }
        } catch (Throwable $e) {
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);
            if ($deliveryStatus === 'pending') {
                $db->insert('webhook_deliveries', [
                    'tenant_id' => $tenantId,
                    'webhook_id' => $webhookId,
                    'event' => $event,
                    'payload' => $jsonPayload,
                    'response_code' => $responseCode ?: 0,
                    'response_body' => substr($e->getMessage(), 0, 1000),
                    'duration_ms' => $durationMs,
                    'status' => 'failed',
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
            throw $e;
        }
    }

    /**
     * @param string $url
     * @param array<string, string> $headers
     * @param string $body
     * @return array{status: int, body: string}
     */
    private function sendHttpRequest(string $url, array $headers, string $body): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $headerLines = [];
            foreach ($headers as $k => $v) {
                $headerLines[] = "{$k}: {$v}";
            }

            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

            $result = curl_exec($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($result === false && $statusCode === 0) {
                throw new Exception("cURL Error: {$error}");
            }

            return ['status' => $statusCode, 'body' => (string) $result];
        }

        // Fallback using stream context
        $headerLines = "";
        foreach ($headers as $k => $v) {
            $headerLines .= "{$k}: {$v}\r\n";
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headerLines,
                'content' => $body,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        $statusCode = 500;
        if (isset($http_response_header) && isset($http_response_header[0])) {
            preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $matches);
            if (isset($matches[1])) {
                $statusCode = (int) $matches[1];
            }
        }

        return ['status' => $statusCode, 'body' => (string) $result];
    }
}
