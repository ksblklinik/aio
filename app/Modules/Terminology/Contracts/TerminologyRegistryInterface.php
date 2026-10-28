<?php

declare(strict_types=1);

namespace App\Modules\Terminology\Contracts;

interface TerminologyRegistryInterface
{
    /**
     * Register a new code system (e.g. ICD-10, ICD-9-CM, LOINC, KFA, HS-Code, KBLI).
     */
    public function createCodeSystem(array $data): array;

    /**
     * Get a code system by its primary ID.
     */
    public function getCodeSystem(int $id): ?array;

    /**
     * Get a code system by its unique code (e.g. 'ICD-10').
     */
    public function getCodeSystemByCode(string $code): ?array;

    /**
     * List all active code systems accessible by current tenant.
     */
    public function listCodeSystems(array $filters = []): array;

    /**
     * Update an existing code system.
     */
    public function updateCodeSystem(int $id, array $data): array;

    /**
     * Delete a code system and its concepts.
     */
    public function deleteCodeSystem(int $id): bool;

    /**
     * Create a single terminology concept under a code system.
     */
    public function createConcept(array $data): array;

    /**
     * Get a concept by its primary ID.
     */
    public function getConcept(int $id): ?array;

    /**
     * Lookup a concept by code system code and concept code.
     */
    public function getConceptByCode(string $systemCode, string $conceptCode): ?array;

    /**
     * Fast autocomplete/search across concepts by code, display name, and natural language synonyms.
     */
    public function searchConcepts(string $query, ?string $systemCode = null, int $limit = 20): array;

    /**
     * List concepts for a specific code system with optional pagination/filters.
     */
    public function listConcepts(int $codeSystemId, array $filters = []): array;

    /**
     * Update an existing concept.
     */
    public function updateConcept(int $id, array $data): array;

    /**
     * Delete a concept.
     */
    public function deleteConcept(int $id): bool;

    /**
     * Create a cross-reference mapping between two code systems (e.g. Internal <-> BPJS <-> SatuSehat).
     */
    public function createMapping(array $data): array;

    /**
     * List cross-reference mappings with optional filters.
     */
    public function listMappings(array $filters = []): array;

    /**
     * Delete a mapping by ID.
     */
    public function deleteMapping(int $id): bool;

    /**
     * Resolve a cross-reference mapping from a source system & code to a target system.
     */
    public function resolveMapping(string $sourceSystemCode, string $sourceCode, string $targetSystemCode): ?array;

    /**
     * Bulk import an array of concepts into a code system efficiently.
     * Returns count of successfully imported concepts.
     */
    public function bulkImportConcepts(int $codeSystemId, array $concepts): int;
}
