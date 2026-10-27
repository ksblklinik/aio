<?php

declare(strict_types=1);

namespace Core\Vector;

use Core\Database\Connection;
use Core\Database\TenantContext;
use InvalidArgumentException;

class SqliteVectorDriver implements VectorStoreInterface
{
    public function __construct(
        private Connection $db,
        private ?TenantContext $context = null
    ) {
        $this->context = $context ?? TenantContext::getInstance();
    }

    private function resolveTenantId(?int $tenantId): int
    {
        if ($tenantId !== null) {
            return $tenantId;
        }

        $active = $this->context->getTenantId();
        return $active !== null ? (int) $active : 0;
    }

    public function upsert(
        string $collection,
        string $itemId,
        array $vector,
        array $metadata = [],
        ?int $tenantId = null
    ): bool {
        if (empty($vector)) {
            throw new InvalidArgumentException("Vector cannot be empty.");
        }

        $resolvedTenantId = $this->resolveTenantId($tenantId);
        $dimensions = count($vector);

        // Pack vector float array into binary float32 blob
        $binaryBlob = pack('f*', ...$vector);
        $metadataJson = !empty($metadata) ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
        $now = date('Y-m-d H:i:s');

        $sql = "INSERT INTO vector_embeddings (tenant_id, collection, item_id, embedding, dimensions, metadata, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(tenant_id, collection, item_id) DO UPDATE SET
                    embedding = excluded.embedding,
                    dimensions = excluded.dimensions,
                    metadata = excluded.metadata,
                    updated_at = excluded.updated_at";

        $this->db->statement($sql, [
            $resolvedTenantId,
            $collection,
            $itemId,
            $binaryBlob,
            $dimensions,
            $metadataJson,
            $now,
            $now,
        ]);

        return true;
    }

    public function search(
        string $collection,
        array $queryVector,
        int $limit = 5,
        float $minScore = 0.0,
        ?int $tenantId = null
    ): array {
        if (empty($queryVector)) {
            return [];
        }

        $resolvedTenantId = $this->resolveTenantId($tenantId);
        $queryDim = count($queryVector);

        $sql = "SELECT item_id, embedding, dimensions, metadata FROM vector_embeddings 
                WHERE tenant_id = ? AND collection = ? AND dimensions = ?";
        
        $rows = $this->db->selectAll($sql, [$resolvedTenantId, $collection, $queryDim]);
        if (empty($rows)) {
            return [];
        }

        $queryNorm = $this->vectorNorm($queryVector);
        if ($queryNorm == 0.0) {
            return [];
        }

        $scored = [];
        foreach ($rows as $row) {
            $rawBlob = $row['embedding'];
            if (!is_string($rawBlob) || $rawBlob === '') {
                continue;
            }

            /** @var array<int, float> $storedVector */
            $unpacked = unpack('f*', $rawBlob);
            if ($unpacked === false) {
                continue;
            }

            $storedVector = array_values($unpacked);
            $sim = $this->cosineSimilarity($queryVector, $storedVector, $queryNorm);

            if ($sim >= $minScore) {
                $meta = !empty($row['metadata']) ? json_decode((string) $row['metadata'], true) : [];
                $scored[] = [
                    'item_id' => (string) $row['item_id'],
                    'score' => round($sim, 6),
                    'metadata' => is_array($meta) ? $meta : [],
                ];
            }
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, max(1, $limit));
    }

    public function delete(string $collection, string $itemId, ?int $tenantId = null): bool
    {
        $resolvedTenantId = $this->resolveTenantId($tenantId);
        $affected = $this->db->delete('vector_embeddings', 'tenant_id = ? AND collection = ? AND item_id = ?', [
            $resolvedTenantId,
            $collection,
            $itemId,
        ]);

        return $affected > 0;
    }

    public function deleteCollection(string $collection, ?int $tenantId = null): int
    {
        $resolvedTenantId = $this->resolveTenantId($tenantId);
        return $this->db->delete('vector_embeddings', 'tenant_id = ? AND collection = ?', [
            $resolvedTenantId,
            $collection,
        ]);
    }

    /**
     * Hitung Cosine Similarity antara dua vector
     *
     * @param array<float> $a
     * @param array<float> $b
     */
    public function cosineSimilarity(array $a, array $b, ?float $normA = null): float
    {
        $count = count($a);
        if ($count !== count($b) || $count === 0) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $normBSq = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normBSq += $b[$i] * $b[$i];
        }

        $normA = $normA ?? $this->vectorNorm($a);
        $normB = sqrt($normBSq);

        if ($normA == 0.0 || $normB == 0.0) {
            return 0.0;
        }

        return $dotProduct / ($normA * $normB);
    }

    /**
     * @param array<float> $v
     */
    private function vectorNorm(array $v): float
    {
        $sum = 0.0;
        foreach ($v as $val) {
            $sum += $val * $val;
        }
        return sqrt($sum);
    }
}
