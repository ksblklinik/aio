<?php

declare(strict_types=1);

namespace Core\Ai;

interface AiClientInterface
{
    /**
     * Kirim percakapan dan dapatkan jawaban lengkap
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed> $options (temperature, model, max_tokens)
     */
    public function chat(array $messages, array $options = []): string;

    /**
     * Streaming jawaban chunk-by-chunk secara real-time
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @param callable(string): void $onChunk Callback untuk setiap potongan token baru
     * @param array<string, mixed> $options
     */
    public function stream(array $messages, callable $onChunk, array $options = []): void;

    /**
     * Hasilkan vector embedding dari suatu teks (untuk semantic search / RAG)
     *
     * @return array<float>
     */
    public function embed(string $text): array;

    /**
     * Ekstraksi informasi dari prompt ke dalam array JSON terstruktur
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function extractJson(string $prompt, array $schema = []): array;
}
