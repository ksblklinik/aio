<?php

declare(strict_types=1);

namespace Core\Backup\Integrity;

class IntegrityReport
{
    /**
     * @param array<string> $integrityCheckErrors
     * @param array<array<string, mixed>> $foreignKeyViolations
     * @param array<string, int> $tableDetails
     * @param array<string, mixed> $diagnostics
     */
    public function __construct(
        public readonly bool $isHealthy,
        public readonly string $databaseType,
        public readonly array $integrityCheckErrors,
        public readonly array $foreignKeyViolations,
        public readonly int $tableCount,
        public readonly int $totalRecordCount,
        public readonly array $tableDetails,
        public readonly array $diagnostics,
        public readonly string $timestamp
    ) {
    }

    public function hasErrors(): bool
    {
        return !$this->isHealthy || !empty($this->integrityCheckErrors) || !empty($this->foreignKeyViolations);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'is_healthy' => $this->isHealthy,
            'database_type' => $this->databaseType,
            'integrity_errors' => $this->integrityCheckErrors,
            'foreign_key_violations' => $this->foreignKeyViolations,
            'table_count' => $this->tableCount,
            'total_record_count' => $this->totalRecordCount,
            'table_details' => $this->tableDetails,
            'diagnostics' => $this->diagnostics,
            'timestamp' => $this->timestamp,
        ];
    }

    public function getSummary(): string
    {
        if ($this->isHealthy) {
            return sprintf(
                'Database integrity is EXCELLENT. %d tables inspected, %d total records. Zero corruptions detected.',
                $this->tableCount,
                $this->totalRecordCount
            );
        }

        $errorCount = count($this->integrityCheckErrors) + count($this->foreignKeyViolations);
        return sprintf(
            'Database integrity WARNING: %d issues detected (%d integrity errors, %d foreign key violations).',
            $errorCount,
            count($this->integrityCheckErrors),
            count($this->foreignKeyViolations)
        );
    }
}
