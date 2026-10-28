<?php

declare(strict_types=1);

namespace App\Modules\Terminology\Services;

use App\Modules\Terminology\Contracts\TerminologyRegistryInterface;
use Core\Database\Connection;
use Core\Database\TenantContext;
use InvalidArgumentException;
use PDO;

class TerminologyService implements TerminologyRegistryInterface
{
    public function __construct(
        protected Connection $db,
        protected TenantContext $tenantContext
    ) {}

    protected function getTenantId(): int
    {
        $id = $this->tenantContext->getTenantId();
        return $id !== null ? (int)$id : 1;
    }

    // =========================================================================
    // 1. CODE SYSTEMS MANAGEMENT
    // =========================================================================

    public function createCodeSystem(array $data): array
    {
        if (empty($data['code']) || empty($data['name'])) {
            throw new InvalidArgumentException("Kode dan Nama Sistem Kodifikasi wajib diisi.");
        }

        $code = strtoupper(trim((string)$data['code']));
        $name = trim((string)$data['name']);
        $uri = !empty($data['uri']) ? trim((string)$data['uri']) : null;
        $version = !empty($data['version']) ? trim((string)$data['version']) : null;
        $publisher = !empty($data['publisher']) ? trim((string)$data['publisher']) : null;
        $description = !empty($data['description']) ? trim((string)$data['description']) : null;
        $isActive = isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1;
        $isSystem = isset($data['is_system']) && $data['is_system'] ? 1 : 0;

        $tenantId = $isSystem === 1 
            ? null 
            : (array_key_exists('tenant_id', $data) ? ($data['tenant_id'] !== null ? (int)$data['tenant_id'] : null) : $this->getTenantId());

        // Check duplicate
        if ($tenantId === null) {
            $stmt = $this->db->pdo()->prepare("SELECT id FROM terminology_code_systems WHERE code = ? AND tenant_id IS NULL");
            $stmt->execute([$code]);
        } else {
            $stmt = $this->db->pdo()->prepare("SELECT id FROM terminology_code_systems WHERE code = ? AND tenant_id = ?");
            $stmt->execute([$code, $tenantId]);
        }

        if ($stmt->fetch()) {
            throw new InvalidArgumentException("Sistem kodifikasi dengan kode '{$code}' sudah terdaftar.");
        }

        $insertStmt = $this->db->pdo()->prepare("
            INSERT INTO terminology_code_systems (
                tenant_id, code, name, uri, version, publisher, description,
                is_active, is_system, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $insertStmt->execute([
            $tenantId, $code, $name, $uri, $version, $publisher, $description,
            $isActive, $isSystem
        ]);

        $newId = (int)$this->db->pdo()->lastInsertId();
        return $this->getCodeSystem($newId);
    }

    public function getCodeSystem(int $id): ?array
    {
        $tenantId = $this->getTenantId();
        $stmt = $this->db->pdo()->prepare("
            SELECT * FROM terminology_code_systems 
            WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)
        ");
        $stmt->execute([$id, $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function getCodeSystemByCode(string $code): ?array
    {
        $tenantId = $this->getTenantId();
        $code = strtoupper(trim($code));

        $stmt = $this->db->pdo()->prepare("
            SELECT * FROM terminology_code_systems 
            WHERE code = ? AND (tenant_id = ? OR tenant_id IS NULL)
            ORDER BY CASE WHEN tenant_id = ? THEN 0 ELSE 1 END
            LIMIT 1
        ");
        $stmt->execute([$code, $tenantId, $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function listCodeSystems(array $filters = []): array
    {
        $tenantId = $this->getTenantId();
        $sql = "SELECT * FROM terminology_code_systems WHERE (tenant_id = ? OR tenant_id IS NULL)";
        $params = [$tenantId];

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $sql .= " AND is_active = ?";
            $params[] = (int)(bool)$filters['is_active'];
        }

        if (!empty($filters['search'])) {
            $search = '%' . trim((string)$filters['search']) . '%';
            $sql .= " AND (code LIKE ? OR name LIKE ? OR description LIKE ?)";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $sql .= " ORDER BY is_system DESC, code ASC";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateCodeSystem(int $id, array $data): array
    {
        $existing = $this->getCodeSystem($id);
        if (!$existing) {
            throw new InvalidArgumentException("Sistem kodifikasi ID {$id} tidak ditemukan.");
        }

        $tenantId = $this->getTenantId();
        if ($existing['is_system'] == 1 && $existing['tenant_id'] === null) {
            // Cannot alter core identity of system-wide code systems unless updating active status
        }

        $name = isset($data['name']) ? trim((string)$data['name']) : $existing['name'];
        $uri = array_key_exists('uri', $data) ? ($data['uri'] ? trim((string)$data['uri']) : null) : $existing['uri'];
        $version = array_key_exists('version', $data) ? ($data['version'] ? trim((string)$data['version']) : null) : $existing['version'];
        $publisher = array_key_exists('publisher', $data) ? ($data['publisher'] ? trim((string)$data['publisher']) : null) : $existing['publisher'];
        $description = array_key_exists('description', $data) ? ($data['description'] ? trim((string)$data['description']) : null) : $existing['description'];
        $isActive = isset($data['is_active']) ? (int)(bool)$data['is_active'] : (int)$existing['is_active'];

        $stmt = $this->db->pdo()->prepare("
            UPDATE terminology_code_systems SET
                name = ?, uri = ?, version = ?, publisher = ?, description = ?,
                is_active = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$name, $uri, $version, $publisher, $description, $isActive, $id]);

        return $this->getCodeSystem($id);
    }

    public function deleteCodeSystem(int $id): bool
    {
        $existing = $this->getCodeSystem($id);
        if (!$existing) {
            return false;
        }

        if ((int)$existing['is_system'] === 1) {
            throw new InvalidArgumentException("Sistem kodifikasi bawaan platform (is_system = 1) tidak dapat dihapus.");
        }

        $tenantId = $this->getTenantId();
        $stmt = $this->db->pdo()->prepare("DELETE FROM terminology_code_systems WHERE id = ? AND tenant_id = ?");
        return $stmt->execute([$id, $tenantId]);
    }

    // =========================================================================
    // 2. CONCEPTS DICTIONARY & SEARCH ENGINE
    // =========================================================================

    public function createConcept(array $data): array
    {
        $codeSystemId = null;
        if (!empty($data['code_system_id'])) {
            $codeSystemId = (int)$data['code_system_id'];
        } elseif (!empty($data['system_code'])) {
            $cs = $this->getCodeSystemByCode((string)$data['system_code']);
            if ($cs) {
                $codeSystemId = (int)$cs['id'];
            }
        }

        if (!$codeSystemId) {
            throw new InvalidArgumentException("Sistem kodifikasi (code_system_id atau system_code) wajib ditentukan.");
        }

        $cs = $this->getCodeSystem($codeSystemId);
        if (!$cs) {
            throw new InvalidArgumentException("Sistem kodifikasi ID {$codeSystemId} tidak ditemukan.");
        }

        if (empty($data['code']) || empty($data['display_name'])) {
            throw new InvalidArgumentException("Kode konsep dan Display Name wajib diisi.");
        }

        $code = trim((string)$data['code']);
        $displayName = trim((string)$data['display_name']);
        $description = !empty($data['description']) ? trim((string)$data['description']) : null;
        $parentCode = !empty($data['parent_code']) ? trim((string)$data['parent_code']) : null;
        $isActive = isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1;

        $tenantId = array_key_exists('tenant_id', $data)
            ? ($data['tenant_id'] !== null ? (int)$data['tenant_id'] : null)
            : ($cs['is_system'] == 1 && ($data['is_global'] ?? false) ? null : $this->getTenantId());

        // Format synonyms
        $synonyms = null;
        if (!empty($data['synonyms'])) {
            $synonyms = is_array($data['synonyms'])
                ? json_encode(array_values(array_filter(array_map('trim', $data['synonyms']))), JSON_UNESCAPED_UNICODE)
                : (is_string($data['synonyms']) ? trim($data['synonyms']) : null);
        }

        // Format properties
        $properties = null;
        if (!empty($data['properties'])) {
            $properties = is_array($data['properties'])
                ? json_encode($data['properties'], JSON_UNESCAPED_UNICODE)
                : (is_string($data['properties']) ? trim($data['properties']) : null);
        }

        // Check duplicate
        if ($tenantId === null) {
            $stmt = $this->db->pdo()->prepare("
                SELECT id FROM terminology_concepts 
                WHERE code_system_id = ? AND code = ? AND tenant_id IS NULL
            ");
            $stmt->execute([$codeSystemId, $code]);
        } else {
            $stmt = $this->db->pdo()->prepare("
                SELECT id FROM terminology_concepts 
                WHERE code_system_id = ? AND code = ? AND tenant_id = ?
            ");
            $stmt->execute([$codeSystemId, $code, $tenantId]);
        }

        if ($stmt->fetch()) {
            throw new InvalidArgumentException("Konsep kode '{$code}' sudah terdaftar dalam sistem kodifikasi ini.");
        }

        $insertStmt = $this->db->pdo()->prepare("
            INSERT INTO terminology_concepts (
                tenant_id, code_system_id, code, display_name, description,
                parent_code, synonyms, properties, is_active, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $insertStmt->execute([
            $tenantId, $codeSystemId, $code, $displayName, $description,
            $parentCode, $synonyms, $properties, $isActive
        ]);

        $newId = (int)$this->db->pdo()->lastInsertId();
        return $this->getConcept($newId);
    }

    public function getConcept(int $id): ?array
    {
        $tenantId = $this->getTenantId();
        $stmt = $this->db->pdo()->prepare("
            SELECT c.*, cs.code as system_code, cs.name as system_name 
            FROM terminology_concepts c 
            JOIN terminology_code_systems cs ON c.code_system_id = cs.id 
            WHERE c.id = ? AND (c.tenant_id = ? OR c.tenant_id IS NULL)
        ");
        $stmt->execute([$id, $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->formatConceptRow($row);
    }

    public function getConceptByCode(string $systemCode, string $conceptCode): ?array
    {
        $tenantId = $this->getTenantId();
        $systemCode = strtoupper(trim($systemCode));
        $conceptCode = trim($conceptCode);

        $stmt = $this->db->pdo()->prepare("
            SELECT c.*, cs.code as system_code, cs.name as system_name 
            FROM terminology_concepts c 
            JOIN terminology_code_systems cs ON c.code_system_id = cs.id 
            WHERE cs.code = ? AND c.code = ? AND (c.tenant_id = ? OR c.tenant_id IS NULL)
            ORDER BY CASE WHEN c.tenant_id = ? THEN 0 ELSE 1 END
            LIMIT 1
        ");
        $stmt->execute([$systemCode, $conceptCode, $tenantId, $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->formatConceptRow($row);
    }

    public function searchConcepts(string $query, ?string $systemCode = null, int $limit = 20): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $tenantId = $this->getTenantId();
        $searchPattern = '%' . $query . '%';
        $prefixPattern = $query . '%';

        $sql = "
            SELECT c.*, cs.code as system_code, cs.name as system_name 
            FROM terminology_concepts c 
            JOIN terminology_code_systems cs ON c.code_system_id = cs.id 
            WHERE c.is_active = 1 AND cs.is_active = 1 
              AND (c.tenant_id = ? OR c.tenant_id IS NULL)
              AND (c.code LIKE ? OR c.display_name LIKE ? OR c.synonyms LIKE ? OR c.description LIKE ?)
        ";
        $params = [$tenantId, $searchPattern, $searchPattern, $searchPattern, $searchPattern];

        if (!empty($systemCode)) {
            $sql .= " AND cs.code = ?";
            $params[] = strtoupper(trim($systemCode));
        }

        $sql .= " 
            ORDER BY 
                CASE 
                    WHEN LOWER(c.code) = LOWER(?) THEN 1 
                    WHEN LOWER(c.code) LIKE LOWER(?) THEN 2 
                    WHEN LOWER(c.display_name) LIKE LOWER(?) THEN 3 
                    ELSE 4 
                END, 
                c.code ASC 
            LIMIT ?
        ";
        $params[] = $query;
        $params[] = $prefixPattern;
        $params[] = $prefixPattern;
        $params[] = max(1, min(100, $limit));

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'formatConceptRow'], $rows);
    }

    public function listConcepts(int $codeSystemId, array $filters = []): array
    {
        $tenantId = $this->getTenantId();
        $sql = "
            SELECT c.*, cs.code as system_code, cs.name as system_name 
            FROM terminology_concepts c 
            JOIN terminology_code_systems cs ON c.code_system_id = cs.id 
            WHERE c.code_system_id = ? AND (c.tenant_id = ? OR c.tenant_id IS NULL)
        ";
        $params = [$codeSystemId, $tenantId];

        if (!empty($filters['parent_code'])) {
            $sql .= " AND c.parent_code = ?";
            $params[] = trim((string)$filters['parent_code']);
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $sql .= " AND c.is_active = ?";
            $params[] = (int)(bool)$filters['is_active'];
        }

        if (!empty($filters['search'])) {
            $search = '%' . trim((string)$filters['search']) . '%';
            $sql .= " AND (c.code LIKE ? OR c.display_name LIKE ? OR c.synonyms LIKE ?)";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $sql .= " ORDER BY c.code ASC";

        if (!empty($filters['limit'])) {
            $limit = max(1, (int)$filters['limit']);
            $offset = isset($filters['offset']) ? max(0, (int)$filters['offset']) : 0;
            $sql .= " LIMIT {$limit} OFFSET {$offset}";
        }

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'formatConceptRow'], $rows);
    }

    public function updateConcept(int $id, array $data): array
    {
        $existing = $this->getConcept($id);
        if (!$existing) {
            throw new InvalidArgumentException("Konsep ID {$id} tidak ditemukan.");
        }

        $displayName = isset($data['display_name']) ? trim((string)$data['display_name']) : $existing['display_name'];
        $description = array_key_exists('description', $data) ? ($data['description'] ? trim((string)$data['description']) : null) : $existing['description'];
        $parentCode = array_key_exists('parent_code', $data) ? ($data['parent_code'] ? trim((string)$data['parent_code']) : null) : $existing['parent_code'];
        $isActive = isset($data['is_active']) ? (int)(bool)$data['is_active'] : (int)$existing['is_active'];

        $synonyms = $existing['synonyms_raw'] ?? null;
        if (array_key_exists('synonyms', $data)) {
            $synonyms = is_array($data['synonyms'])
                ? json_encode(array_values(array_filter(array_map('trim', $data['synonyms']))), JSON_UNESCAPED_UNICODE)
                : (is_string($data['synonyms']) ? trim($data['synonyms']) : null);
        }

        $properties = $existing['properties_raw'] ?? null;
        if (array_key_exists('properties', $data)) {
            $properties = is_array($data['properties'])
                ? json_encode($data['properties'], JSON_UNESCAPED_UNICODE)
                : (is_string($data['properties']) ? trim($data['properties']) : null);
        }

        $stmt = $this->db->pdo()->prepare("
            UPDATE terminology_concepts SET
                display_name = ?, description = ?, parent_code = ?,
                synonyms = ?, properties = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$displayName, $description, $parentCode, $synonyms, $properties, $isActive, $id]);

        return $this->getConcept($id);
    }

    public function deleteConcept(int $id): bool
    {
        $existing = $this->getConcept($id);
        if (!$existing) {
            return false;
        }

        $tenantId = $this->getTenantId();
        $stmt = $this->db->pdo()->prepare("DELETE FROM terminology_concepts WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)");
        return $stmt->execute([$id, $tenantId]);
    }

    // =========================================================================
    // 3. CROSS-REFERENCE MAPPINGS
    // =========================================================================

    public function createMapping(array $data): array
    {
        $tenantId = $this->getTenantId();

        $sourceSystemId = $this->resolveCodeSystemId($data['source_system_id'] ?? null, $data['source_system_code'] ?? null);
        $targetSystemId = $this->resolveCodeSystemId($data['target_system_id'] ?? null, $data['target_system_code'] ?? null);

        if (!$sourceSystemId || !$targetSystemId) {
            throw new InvalidArgumentException("Sistem kodifikasi sumber dan target wajib valid.");
        }

        if (empty($data['source_code']) || empty($data['target_code'])) {
            throw new InvalidArgumentException("Kode sumber (source_code) dan kode target (target_code) wajib diisi.");
        }

        $sourceCode = trim((string)$data['source_code']);
        $targetCode = trim((string)$data['target_code']);
        $equivalence = !empty($data['equivalence']) ? trim((string)$data['equivalence']) : 'equivalent';
        $comments = !empty($data['comments']) ? trim((string)$data['comments']) : null;

        $stmt = $this->db->pdo()->prepare("
            INSERT INTO terminology_mappings (
                tenant_id, source_system_id, source_code, target_system_id, target_code,
                equivalence, comments, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $tenantId, $sourceSystemId, $sourceCode, $targetSystemId, $targetCode,
            $equivalence, $comments
        ]);

        $newId = (int)$this->db->pdo()->lastInsertId();
        return $this->getMapping($newId);
    }

    public function getMapping(int $id): ?array
    {
        $tenantId = $this->getTenantId();
        $stmt = $this->db->pdo()->prepare("
            SELECT m.*, 
                   src.code as source_system_code, src.name as source_system_name,
                   tgt.code as target_system_code, tgt.name as target_system_name
            FROM terminology_mappings m
            JOIN terminology_code_systems src ON m.source_system_id = src.id
            JOIN terminology_code_systems tgt ON m.target_system_id = tgt.id
            WHERE m.id = ? AND m.tenant_id = ?
        ");
        $stmt->execute([$id, $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function listMappings(array $filters = []): array
    {
        $tenantId = $this->getTenantId();
        $sql = "
            SELECT m.*, 
                   src.code as source_system_code, src.name as source_system_name,
                   tgt.code as target_system_code, tgt.name as target_system_name
            FROM terminology_mappings m
            JOIN terminology_code_systems src ON m.source_system_id = src.id
            JOIN terminology_code_systems tgt ON m.target_system_id = tgt.id
            WHERE m.tenant_id = ?
        ";
        $params = [$tenantId];

        if (!empty($filters['source_system_id'])) {
            $sql .= " AND m.source_system_id = ?";
            $params[] = (int)$filters['source_system_id'];
        }

        if (!empty($filters['target_system_id'])) {
            $sql .= " AND m.target_system_id = ?";
            $params[] = (int)$filters['target_system_id'];
        }

        if (!empty($filters['search'])) {
            $search = '%' . trim((string)$filters['search']) . '%';
            $sql .= " AND (m.source_code LIKE ? OR m.target_code LIKE ? OR m.comments LIKE ?)";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $sql .= " ORDER BY m.created_at DESC";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deleteMapping(int $id): bool
    {
        $tenantId = $this->getTenantId();
        $stmt = $this->db->pdo()->prepare("DELETE FROM terminology_mappings WHERE id = ? AND tenant_id = ?");
        return $stmt->execute([$id, $tenantId]);
    }

    public function resolveMapping(string $sourceSystemCode, string $sourceCode, string $targetSystemCode): ?array
    {
        $tenantId = $this->getTenantId();
        $sourceSystemCode = strtoupper(trim($sourceSystemCode));
        $targetSystemCode = strtoupper(trim($targetSystemCode));
        $sourceCode = trim($sourceCode);

        $stmt = $this->db->pdo()->prepare("
            SELECT m.*, 
                   src.code as source_system_code, src.name as source_system_name,
                   tgt.code as target_system_code, tgt.name as target_system_name
            FROM terminology_mappings m
            JOIN terminology_code_systems src ON m.source_system_id = src.id
            JOIN terminology_code_systems tgt ON m.target_system_id = tgt.id
            WHERE src.code = ? AND m.source_code = ? AND tgt.code = ? AND m.tenant_id = ?
            ORDER BY m.id DESC
            LIMIT 1
        ");
        $stmt->execute([$sourceSystemCode, $sourceCode, $targetSystemCode, $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        // Fetch concept details of target code if available
        $targetConcept = $this->getConceptByCode($targetSystemCode, (string)$row['target_code']);

        return [
            'mapping_id' => (int)$row['id'],
            'source_system_code' => $row['source_system_code'],
            'source_system_name' => $row['source_system_name'],
            'source_code' => $row['source_code'],
            'target_system_code' => $row['target_system_code'],
            'target_system_name' => $row['target_system_name'],
            'target_code' => $row['target_code'],
            'equivalence' => $row['equivalence'],
            'comments' => $row['comments'],
            'target_concept' => $targetConcept,
        ];
    }

    // =========================================================================
    // 4. BULK IMPORTER
    // =========================================================================

    public function bulkImportConcepts(int $codeSystemId, array $concepts): int
    {
        $cs = $this->getCodeSystem($codeSystemId);
        if (!$cs) {
            throw new InvalidArgumentException("Sistem kodifikasi ID {$codeSystemId} tidak ditemukan.");
        }

        if (empty($concepts)) {
            return 0;
        }

        $tenantId = $cs['is_system'] == 1 ? null : $this->getTenantId();
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare("
                INSERT OR REPLACE INTO terminology_concepts (
                    tenant_id, code_system_id, code, display_name, description,
                    parent_code, synonyms, properties, is_active, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");

            $count = 0;
            foreach ($concepts as $c) {
                if (empty($c['code']) || empty($c['display_name'])) {
                    continue;
                }

                $code = trim((string)$c['code']);
                $displayName = trim((string)$c['display_name']);
                $description = !empty($c['description']) ? trim((string)$c['description']) : null;
                $parentCode = !empty($c['parent_code']) ? trim((string)$c['parent_code']) : null;
                $isActive = isset($c['is_active']) ? (int)(bool)$c['is_active'] : 1;

                $synonyms = null;
                if (!empty($c['synonyms'])) {
                    $synonyms = is_array($c['synonyms'])
                        ? json_encode(array_values(array_filter(array_map('trim', $c['synonyms']))), JSON_UNESCAPED_UNICODE)
                        : (is_string($c['synonyms']) ? trim($c['synonyms']) : null);
                }

                $properties = null;
                if (!empty($c['properties'])) {
                    $properties = is_array($c['properties'])
                        ? json_encode($c['properties'], JSON_UNESCAPED_UNICODE)
                        : (is_string($c['properties']) ? trim($c['properties']) : null);
                }

                $stmt->execute([
                    $tenantId, $codeSystemId, $code, $displayName, $description,
                    $parentCode, $synonyms, $properties, $isActive
                ]);
                $count++;
            }

            $pdo->commit();
            return $count;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    protected function resolveCodeSystemId(?int $id, ?string $code): ?int
    {
        if ($id) {
            return $id;
        }

        if ($code) {
            $cs = $this->getCodeSystemByCode($code);
            return $cs ? (int)$cs['id'] : null;
        }

        return null;
    }

    protected function formatConceptRow(array $row): array
    {
        $row['synonyms_raw'] = $row['synonyms'];
        $row['properties_raw'] = $row['properties'];

        if (!empty($row['synonyms'])) {
            $decoded = json_decode((string)$row['synonyms'], true);
            $row['synonyms'] = is_array($decoded) ? $decoded : [$row['synonyms']];
        } else {
            $row['synonyms'] = [];
        }

        if (!empty($row['properties'])) {
            $decoded = json_decode((string)$row['properties'], true);
            $row['properties'] = is_array($decoded) ? $decoded : [];
        } else {
            $row['properties'] = [];
        }

        return $row;
    }
}
