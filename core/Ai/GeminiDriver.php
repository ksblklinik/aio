<?php

declare(strict_types=1);

namespace Core\Ai;

use RuntimeException;

class GeminiDriver implements AiClientInterface
{
    /**
     * @param array{
     *     api_key?: string,
     *     model?: string,
     *     base_url?: string,
     *     timeout?: int
     * } $config
     */
    public function __construct(private array $config = [])
    {
    }

    private function getApiKey(): string
    {
        $key = (string) ($this->config['api_key'] ?? '');
        if ($key === '') {
            throw new RuntimeException("Gemini API Key is not configured. Set GEMINI_API_KEY in .env.");
        }
        return $key;
    }

    private function getBaseUrl(): string
    {
        return rtrim((string) ($this->config['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta'), '/');
    }

    private function getModel(array $options): string
    {
        return (string) ($options['model'] ?? $this->config['model'] ?? 'gemini-1.5-flash');
    }

    public function chat(array $messages, array $options = []): string
    {
        $model = $this->getModel($options);
        $contents = $this->formatMessages($messages);

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => (float) ($options['temperature'] ?? 0.7),
            ],
        ];

        $endpoint = "/models/{$model}:generateContent?key=" . $this->getApiKey();
        $response = $this->request($endpoint, $payload);

        return (string) ($response['candidates'][0]['content']['parts'][0]['text'] ?? '');
    }

    public function stream(array $messages, callable $onChunk, array $options = []): void
    {
        $model = $this->getModel($options);
        $contents = $this->formatMessages($messages);

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => (float) ($options['temperature'] ?? 0.7),
            ],
        ];

        $endpoint = "/models/{$model}:streamGenerateContent?alt=sse&key=" . $this->getApiKey();
        $url = $this->getBaseUrl() . $endpoint;

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException("Could not initialize cURL for Gemini streaming.");
        }

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) ($this->config['timeout'] ?? 60));

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use ($onChunk) {
            $lines = explode("\n", (string) $data);
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (!str_starts_with($trimmed, 'data: ')) {
                    continue;
                }
                $jsonStr = substr($trimmed, 6);
                $parsed = json_decode($jsonStr, true);
                if (isset($parsed['candidates'][0]['content']['parts'][0]['text'])) {
                    $onChunk((string) $parsed['candidates'][0]['content']['parts'][0]['text']);
                }
            }
            return strlen($data);
        });

        curl_exec($ch);
        curl_close($ch);
    }

    public function embed(string $text): array
    {
        $payload = [
            'model' => 'models/text-embedding-004',
            'content' => [
                'parts' => [
                    ['text' => $text],
                ],
            ],
        ];

        $endpoint = "/models/text-embedding-004:embedContent?key=" . $this->getApiKey();
        $response = $this->request($endpoint, $payload);

        return (array) ($response['embedding']['values'] ?? []);
    }

    public function extractJson(string $prompt, array $schema = []): array
    {
        $instruction = "You are an accurate structured data extraction engine. Return valid JSON only.";
        if (!empty($schema)) {
            $instruction .= " Required JSON Schema: " . json_encode($schema);
        }

        $messages = [
            ['role' => 'user', 'content' => $instruction . "\n\nInput:\n" . $prompt],
        ];

        $rawResponse = $this->chat($messages, ['temperature' => 0.0]);
        $clean = trim($rawResponse);
        if (str_starts_with($clean, '```json')) {
            $clean = substr($clean, 7);
        }
        if (str_starts_with($clean, '```')) {
            $clean = substr($clean, 3);
        }
        if (str_ends_with($clean, '```')) {
            $clean = substr($clean, 0, -3);
        }

        $decoded = json_decode(trim($clean), true);
        return is_array($decoded) ? $decoded : ['raw' => $rawResponse];
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return array<int, array<string, mixed>>
     */
    private function formatMessages(array $messages): array
    {
        $formatted = [];
        foreach ($messages as $msg) {
            $role = match ($msg['role'] ?? 'user') {
                'assistant' => 'model',
                default => 'user',
            };

            $formatted[] = [
                'role' => $role,
                'parts' => [
                    ['text' => (string) ($msg['content'] ?? '')],
                ],
            ];
        }
        return $formatted;
    }

    private function request(string $endpoint, array $payload): array
    {
        $url = $this->getBaseUrl() . $endpoint;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException("Could not initialize cURL for Gemini request.");
        }

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) ($this->config['timeout'] ?? 30));

        $output = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($output === false || $err !== '') {
            throw new RuntimeException("Gemini request failed: {$err}");
        }

        if ($status >= 400) {
            throw new RuntimeException("Gemini returned HTTP {$status}: {$output}");
        }

        $result = json_decode((string) $output, true);
        return is_array($result) ? $result : [];
    }
}
