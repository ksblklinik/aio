<?php

declare(strict_types=1);

namespace Core\Vector;

interface VectorStoreInterface
{
    /**
     * Simpan atau perbarui vector embedding
     *
     * @param string $collection Nama koleksi (e.g. 'knowledge_base', 'products', 'tickets')
     * @param string $itemId ID unik entitas
     * @param array<float> $vector Nilai float array embedding
     * @param array<string, mixed> $metadata Payload metadata tambahan
     * @param int|null $tenantId Override tenant id
     */
    public function upsert(
        string $collection,
        string $itemId,
        array $vector,
        array $metadata = [],
        ?int $tenantId = null
    ): bool;

    /**
     * Cari entitas yang paling mirip (k-NN) menggunakan Cosine Similarity
     *
     * @param string $collection Nama koleksi
     * @param array<float> $queryVector Vector yang dicari
     * @param int $limit Jumlah hasil teratas
     * @param float $minScore Ambang batas minimal cosine similarity (0.0 s.d 1.0)
     * @param int|null $tenantId Override tenant id
     * @return array<int, array{item_id: string, score: float, metadata: array<string, mixed>}>
     */
    public function search(
        string $collection,
        array $queryVector,
        int $limit = 5,
        float $minScore = 0.0,
        ?int $tenantId = null
    ): array;

    /**
     * Hapus satu item embedding
     */
    public function delete(string $collection, string $itemId, ?int $tenantId = null): bool;

    /**
     * Hapus seluruh embedding dalam satu koleksi
     */
    public function deleteCollection(string $collection, ?int $tenantId = null): int;
}
