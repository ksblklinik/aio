<?php

declare(strict_types=1);

namespace Core\Ai;

class MockAiDriver implements AiClientInterface
{
    public function __construct(
        private string $defaultResponse = 'Ini adalah respons simulasi dari Mock AI Driver Core aio.'
    ) {
    }

    public function chat(array $messages, array $options = []): string
    {
        $lastMessage = end($messages);
        $content = is_array($lastMessage) ? ($lastMessage['content'] ?? '') : '';

        if (str_contains(strtolower($content), 'halo') || str_contains(strtolower($content), 'hello')) {
            return "Halo! Ada yang bisa saya bantu dengan modul sistem Anda?";
        }

        return $this->defaultResponse;
    }

    public function stream(array $messages, callable $onChunk, array $options = []): void
    {
        $fullText = $this->chat($messages, $options);
        $words = explode(' ', $fullText);

        foreach ($words as $i => $word) {
            $chunk = ($i > 0 ? ' ' : '') . $word;
            $onChunk($chunk);
        }
    }

    public function embed(string $text, int $dimensions = 8): array
    {
        $vector = [];
        $clean = strtolower(trim($text));
        $len = strlen($clean);

        for ($i = 0; $i < $dimensions; $i++) {
            $hash = crc32($clean . '-' . $i);
            // Normalisasi float antara -1.0 dan 1.0
            $val = (($hash % 2000) - 1000) / 1000.0;
            $vector[] = round($val, 4);
        }

        return $vector;
    }

    public function extractJson(string $prompt, array $schema = []): array
    {
        if (!empty($schema)) {
            $result = [];
            foreach ($schema as $key => $type) {
                $result[$key] = match ($type) {
                    'int', 'integer' => 100,
                    'bool', 'boolean' => true,
                    'array' => ['item_1', 'item_2'],
                    default => "Mock value for {$key}",
                };
            }
            return $result;
        }

        return [
            'status' => 'success',
            'summary' => 'Ekstraksi JSON simulasi berhasil',
            'source_prompt_length' => strlen($prompt),
        ];
    }
}
