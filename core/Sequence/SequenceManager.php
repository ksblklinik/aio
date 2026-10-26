<?php

declare(strict_types=1);

namespace Core\Sequence;

use Core\Database\Connection;
use Core\Database\TenantContext;
use RuntimeException;

class SequenceManager
{
    public function __construct(
        protected Connection $db
    ) {
    }

    /**
     * Resolve the active tenant ID either from parameter or active TenantContext.
     */
    public function resolveTenantId(?int $tenantId = null): int
    {
        if ($tenantId !== null) {
            return $tenantId;
        }

        $contextId = TenantContext::getInstance()->getTenantId();
        if ($contextId !== null && $contextId !== '') {
            return (int) $contextId;
        }

        throw new RuntimeException('SequenceManager requires an active TenantContext or explicit tenantId.');
    }

    /**
     * Atomically generate the next sequential document number for the specified sequence name.
     *
     * @param string $name Sequence identifier (e.g. 'invoice', 'receipt', 'donation', 'medical_record')
     * @param string|null $prefix Optional prefix (e.g. 'INV', 'RCT', 'DON')
     * @param int $padding Zero-padding digits (default 5, e.g. 00001)
     * @param string $resetPeriod Reset cycle: 'never', 'yearly', or 'monthly'
     * @param string|null $formatPattern Optional custom pattern: e.g. '{PREFIX}/{YEAR}/{NUMBER}'
     * @param int|null $tenantId
     * @return string Formatted sequential string (e.g. 'INV/2026/00001' or 'INV-00042')
     */
    public function next(
        string $name,
        ?string $prefix = null,
        int $padding = 5,
        string $resetPeriod = 'never',
        ?string $formatPattern = null,
        ?int $tenantId = null
    ): string {
        $tId = $this->resolveTenantId($tenantId);
        $currentYear = (int) date('Y');
        $currentMonth = (int) date('n');

        return $this->db->transaction(function () use (
            $tId,
            $name,
            $prefix,
            $padding,
            $resetPeriod,
            $formatPattern,
            $currentYear,
            $currentMonth
        ) {
            $row = $this->db->selectOne(
                "SELECT id, prefix, current_value, padding, reset_period, last_reset_year, last_reset_month
                 FROM sequences
                 WHERE tenant_id = ? AND name = ?",
                [$tId, $name]
            );

            if (!$row) {
                $actualPrefix = $prefix ?? '';
                $newValue = 1;

                $this->db->insert('sequences', [
                    'tenant_id' => $tId,
                    'name' => $name,
                    'prefix' => $actualPrefix,
                    'current_value' => $newValue,
                    'padding' => $padding,
                    'reset_period' => $resetPeriod,
                    'last_reset_year' => $currentYear,
                    'last_reset_month' => $currentMonth,
                ]);

                return $this->formatSequenceString(
                    $actualPrefix,
                    $newValue,
                    $padding,
                    $resetPeriod,
                    $formatPattern,
                    $currentYear,
                    $currentMonth
                );
            }

            // Existing sequence record
            $actualPrefix = $prefix !== null ? $prefix : (string) ($row['prefix'] ?? '');
            $actualPadding = $padding > 0 ? $padding : (int) ($row['padding'] ?? 5);
            $actualPeriod = $resetPeriod !== 'never' ? $resetPeriod : (string) ($row['reset_period'] ?? 'never');

            $lastYear = isset($row['last_reset_year']) ? (int) $row['last_reset_year'] : null;
            $lastMonth = isset($row['last_reset_month']) ? (int) $row['last_reset_month'] : null;
            $currentVal = (int) ($row['current_value'] ?? 0);

            // Check reset condition
            $shouldReset = false;
            if ($actualPeriod === 'yearly' && $lastYear !== null && $lastYear !== $currentYear) {
                $shouldReset = true;
            } elseif ($actualPeriod === 'monthly' && ($lastYear !== $currentYear || $lastMonth !== $currentMonth)) {
                $shouldReset = true;
            }

            $newValue = $shouldReset ? 1 : ($currentVal + 1);

            $this->db->statement(
                "UPDATE sequences
                 SET current_value = ?,
                     prefix = ?,
                     padding = ?,
                     reset_period = ?,
                     last_reset_year = ?,
                     last_reset_month = ?,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?",
                [
                    $newValue,
                    $actualPrefix,
                    $actualPadding,
                    $actualPeriod,
                    $currentYear,
                    $currentMonth,
                    $row['id']
                ]
            );

            return $this->formatSequenceString(
                $actualPrefix,
                $newValue,
                $actualPadding,
                $actualPeriod,
                $formatPattern,
                $currentYear,
                $currentMonth
            );
        });
    }

    /**
     * Inspect the current counter without incrementing it.
     */
    public function peek(string $name, ?int $tenantId = null): int
    {
        $tId = $this->resolveTenantId($tenantId);

        $row = $this->db->selectOne(
            "SELECT current_value FROM sequences WHERE tenant_id = ? AND name = ?",
            [$tId, $name]
        );

        return $row ? (int) $row['current_value'] : 0;
    }

    /**
     * Manually override or reset the counter for a sequence.
     */
    public function reset(string $name, int $value = 0, ?int $tenantId = null): void
    {
        $tId = $this->resolveTenantId($tenantId);

        $this->db->statement(
            "UPDATE sequences SET current_value = ?, updated_at = CURRENT_TIMESTAMP WHERE tenant_id = ? AND name = ?",
            [$value, $tId, $name]
        );
    }

    /**
     * Format the raw increment value into a standard readable document code.
     */
    protected function formatSequenceString(
        string $prefix,
        int $value,
        int $padding,
        string $resetPeriod,
        ?string $pattern,
        int $year,
        int $month
    ): string {
        $padded = str_pad((string) $value, $padding, '0', STR_PAD_LEFT);
        $monthPadded = str_pad((string) $month, 2, '0', STR_PAD_LEFT);
        $shortYear = substr((string) $year, -2);

        if ($pattern !== null && $pattern !== '') {
            return str_replace(
                ['{PREFIX}', '{YEAR}', '{YEAR_SHORT}', '{MONTH}', '{NUMBER}'],
                [$prefix, (string) $year, $shortYear, $monthPadded, $padded],
                $pattern
            );
        }

        // Automatic clean standard default patterns
        return match ($resetPeriod) {
            'yearly' => $prefix !== '' ? "{$prefix}/{$year}/{$padded}" : "{$year}/{$padded}",
            'monthly' => $prefix !== '' ? "{$prefix}/{$year}{$monthPadded}/{$padded}" : "{$year}{$monthPadded}/{$padded}",
            default => $prefix !== '' ? "{$prefix}-{$padded}" : $padded,
        };
    }
}
