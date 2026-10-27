<?php

declare(strict_types=1);

namespace Core\Ai;

use RuntimeException;

class OpenAiDriver implements AiClientInterface
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
            throw new RuntimeException("OpenAI API Key is not configured. Set OPENAI_API_KEY in .env.");
        }
        return $key;
    }

    private function getBaseUrl(): string
    {
        return rtrim((string) ($this->config['base_url'] ?? 'https://api.openai.com/v1'), '/');
    }

    private function getModel(array $options): string
    {
        return (string) ($options['model'] ?? $this->config['model'] ?? 'gpt-4o-mini');
    }

    public function chat(array $messages, array $options = []): string
    {
        $payload = [
            'model' => $this->getModel($options),
            'messages' => $messages,
            'temperature' => (float) ($options['temperature'] ?? 0.7),
        ];

        $response = $this->request('/chat/completions', $payload);
        return (string) ($response['choices'][0]['message']['content'] ?? '');
    }

    public function stream(array $messages, callable $onChunk, array $options = []): void
    {
        $payload = [
            'model' => $this->getModel($options),
            'messages' => $messages,
            'temperature' => (float) ($options['temperature'] ?? 0.7),
            'stream' => true,
        ];

        $url = $this->getBaseUrl() . '/chat/completions';
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException("Could not initialize cURL for OpenAI streaming.");
        }

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->getApiKey(),
        ];

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) ($this->config['timeout'] ?? 60));

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use ($onChunk) {
            $lines = explode("\n", (string) $data);
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (!str_starts_with($trimmed, 'data: ')) {
                    continue;
                }
                $jsonStr = substr($trimmed, 6);
                if ($jsonStr === '[DONE]') {
                    break;
                }
                $parsed = json_decode($jsonStr, true);
                if (isset($parsed['choices'][0]['delta']['content'])) {
                    $onChunk((string) $parsed['choices'][0]['delta']['content']);
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
            'model' => 'text-embedding-3-small',
            'input' => $text,
        ];

        $response = $this->request('/embeddings', $payload);
        return (array) ($response['data'][0]['embedding'] ?? []);
    }

    public function extractJson(string $prompt, array $schema = []): array
    {
        $systemMsg = "You are an accurate structured data extraction engine. Output valid JSON only, without markdown wrapping or conversational text.";
        if (!empty($schema)) {
            $systemMsg .= " Required JSON Schema: " . json_encode($schema);
        }

        $messages = [
            ['role' => 'system', 'content' => $systemMsg],
            ['role' => 'user', 'content' => $prompt],
        ];

        $rawResponse = $this->chat($messages, ['temperature' => 0.0]);
        // Hilangkan backticks jika ada
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

    private function request(string $endpoint, array $payload): array
    {
        $url = $this->getBaseUrl() . $endpoint;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException("Could not initialize cURL for OpenAI request.");
        }

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->getApiKey(),
        ];

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) ($this->config['timeout'] ?? 30));

        $output = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($output === false || $err !== '') {
            throw new RuntimeException("OpenAI request failed: {$err}");
        }

        if ($status >= 400) {
            throw new RuntimeException("OpenAI returned HTTP {$status}: {$output}");
        }

        $result = json_decode((string) $output, true);
        return is_array($result) ? $result : [];
    }
}
