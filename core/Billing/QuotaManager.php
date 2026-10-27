<?php

declare(strict_types=1);

namespace Core\Billing;

use Core\Database\Connection;
use Core\Database\TenantContext;
use DateTimeImmutable;

class QuotaManager
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

    // ==========================================
    // 1. FEATURE ENTITLEMENT (GATING)
    // ==========================================

    /**
     * Periksa apakah suatu fitur diaktifkan untuk tenant
     */
    public function can(string $featureKey, ?int $tenantId = null): bool
    {
        $tid = $this->resolveTenantId($tenantId);
        $stmt = $this->db->getPdo()->prepare("
            SELECT is_enabled FROM tenant_entitlements
            WHERE tenant_id = ? AND feature_key = ?
        ");
        $stmt->execute([$tid, $featureKey]);
        $val = $stmt->fetchColumn();

        if ($val === false) {
            return false;
        }

        return (bool) $val;
    }

    /**
     * Konfigurasi akses fitur tenant (boolean)
     */
    public function setFeature(string $featureKey, bool $enabled, ?int $tenantId = null): void
    {
        $tid = $this->resolveTenantId($tenantId);
        $stmt = $this->db->getPdo()->prepare("
            INSERT INTO tenant_entitlements (tenant_id, feature_key, is_enabled, created_at, updated_at)
            VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON CONFLICT(tenant_id, feature_key) DO UPDATE SET
                is_enabled = excluded.is_enabled,
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$tid, $featureKey, $enabled ? 1 : 0]);
    }

    /**
     * Dapatkan daftar seluruh entitlement fitur tenant
     *
     * @return array<string, bool>
     */
    public function features(?int $tenantId = null): array
    {
        $tid = $this->resolveTenantId($tenantId);
        $stmt = $this->db->getPdo()->prepare("
            SELECT feature_key, is_enabled FROM tenant_entitlements
            WHERE tenant_id = ?
        ");
        $stmt->execute([$tid]);

        $results = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $results[(string) $row['feature_key']] = (bool) $row['is_enabled'];
        }

        return $results;
    }

    // ==========================================
    // 2. USAGE QUOTA METERING
    // ==========================================

    /**
     * Hitung tanggal awal dan akhir berdasarkan periode siklus
     *
     * @return array{0: string, 1: string|null}
     */
    private function calculatePeriodBounds(string $period): array
    {
        $now = new DateTimeImmutable();
        return match (strtolower($period)) {
            'daily' => [
                $now->setTime(0, 0, 0)->format('Y-m-d H:i:s'),
                $now->setTime(23, 59, 59)->format('Y-m-d H:i:s'),
            ],
            'monthly' => [
                $now->modify('first day of this month 00:00:00')->format('Y-m-d H:i:s'),
                $now->modify('last day of this month 23:59:59')->format('Y-m-d H:i:s'),
            ],
            'yearly' => [
                $now->setDate((int) $now->format('Y'), 1, 1)->setTime(0, 0, 0)->format('Y-m-d H:i:s'),
                $now->setDate((int) $now->format('Y'), 12, 31)->setTime(23, 59, 59)->format('Y-m-d H:i:s'),
            ],
            default => [
                $now->format('Y-m-d H:i:s'),
                null, // lifetime tidak memiliki batas akhir
            ],
        };
    }

    /**
     * Tetapkan batasan kuota untuk metrik tertentu
     *
     * @param int $limit Batas maksimal pemakaian (-1 untuk unlimited)
     * @param string $period Siklus reset: 'lifetime', 'daily', 'monthly', 'yearly'
     */
    public function setLimit(string $metricKey, int $limit, string $period = 'monthly', ?int $tenantId = null): void
    {
        $tid = $this->resolveTenantId($tenantId);
        [$start, $end] = $this->calculatePeriodBounds($period);

        $stmt = $this->db->getPdo()->prepare("
            INSERT INTO tenant_quotas (tenant_id, metric_key, quota_limit, current_usage, period, period_start, period_end, updated_at)
            VALUES (?, ?, ?, 0, ?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(tenant_id, metric_key) DO UPDATE SET
                quota_limit = excluded.quota_limit,
                period = excluded.period,
                period_start = excluded.period_start,
                period_end = excluded.period_end,
                updated_at = CURRENT_TIMESTAMP
        ");

        $stmt->execute([$tid, $metricKey, $limit, $period, $start, $end]);
    }

    /**
     * Ambil data kuota tenant saat ini dengan evaluasi auto-reset siklus
     *
     * @return array<string, mixed>|null
     */
    private function getOrResetQuota(string $metricKey, int $tid): ?array
    {
        $stmt = $this->db->getPdo()->prepare("
            SELECT * FROM tenant_quotas
            WHERE tenant_id = ? AND metric_key = ?
        ");
        $stmt->execute([$tid, $metricKey]);
        $quota = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$quota) {
            return null;
        }

        // Cek apakah periode telah kedaluwarsa (auto cycle reset)
        if (!empty($quota['period_end']) && strtotime((string) $quota['period_end']) < time()) {
            [$newStart, $newEnd] = $this->calculatePeriodBounds((string) $quota['period']);
            $resetStmt = $this->db->getPdo()->prepare("
                UPDATE tenant_quotas
                SET current_usage = 0, period_start = ?, period_end = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $resetStmt->execute([$newStart, $newEnd, $quota['id']]);

            $quota['current_usage'] = 0;
            $quota['period_start'] = $newStart;
            $quota['period_end'] = $newEnd;
        }

        return $quota;
    }

    /**
     * Konsumsi kuota sejumlah $count. Mengembalikan true jika berhasil, atau false jika kuota habis.
     */
    public function consume(string $metricKey, int $count = 1, ?int $tenantId = null): bool
    {
        $tid = $this->resolveTenantId($tenantId);
        $quota = $this->getOrResetQuota($metricKey, $tid);

        if (!$quota) {
            // Jika metrik belum didefinisikan batasnya, izinkan secara default
            return true;
        }

        $limit = (int) $quota['quota_limit'];
        $current = (int) $quota['current_usage'];

        // Unlimited
        if ($limit === -1) {
            $stmt = $this->db->getPdo()->prepare("
                UPDATE tenant_quotas
                SET current_usage = current_usage + ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$count, $quota['id']]);
            return true;
        }

        // Cek apakah masih dalam batas
        if ($current + $count <= $limit) {
            $stmt = $this->db->getPdo()->prepare("
                UPDATE tenant_quotas
                SET current_usage = current_usage + ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$count, $quota['id']]);
            return true;
        }

        return false;
    }

    /**
     * Dapatkan sisa kuota yang tersedia
     */
    public function remaining(string $metricKey, ?int $tenantId = null): int
    {
        $tid = $this->resolveTenantId($tenantId);
        $quota = $this->getOrResetQuota($metricKey, $tid);

        if (!$quota) {
            return PHP_INT_MAX;
        }

        $limit = (int) $quota['quota_limit'];
        if ($limit === -1) {
            return PHP_INT_MAX;
        }

        $current = (int) $quota['current_usage'];
        return max(0, $limit - $current);
    }

    /**
     * Periksa apakah kuota telah terlampaui
     */
    public function isExceeded(string $metricKey, ?int $tenantId = null): bool
    {
        $tid = $this->resolveTenantId($tenantId);
        $quota = $this->getOrResetQuota($metricKey, $tid);

        if (!$quota) {
            return false;
        }

        $limit = (int) $quota['quota_limit'];
        if ($limit === -1) {
            return false;
        }

        return (int) $quota['current_usage'] >= $limit;
    }

    /**
     * Dapatkan rangkuman status pemakaian kuota
     *
     * @return array<string, mixed>
     */
    public function usage(string $metricKey, ?int $tenantId = null): array
    {
        $tid = $this->resolveTenantId($tenantId);
        $quota = $this->getOrResetQuota($metricKey, $tid);

        if (!$quota) {
            return [
                'metric' => $metricKey,
                'limit' => -1,
                'current' => 0,
                'remaining' => PHP_INT_MAX,
                'is_exceeded' => false,
                'period' => 'lifetime',
                'period_end' => null,
            ];
        }

        $limit = (int) $quota['quota_limit'];
        $current = (int) $quota['current_usage'];
        $remaining = ($limit === -1) ? PHP_INT_MAX : max(0, $limit - $current);

        return [
            'metric' => $metricKey,
            'limit' => $limit,
            'current' => $current,
            'remaining' => $remaining,
            'is_exceeded' => ($limit !== -1) && ($current >= $limit),
            'period' => (string) $quota['period'],
            'period_end' => $quota['period_end'],
        ];
    }

    /**
     * Reset manual pemakaian kuota menjadi 0
     */
    public function reset(string $metricKey, ?int $tenantId = null): void
    {
        $tid = $this->resolveTenantId($tenantId);
        $stmt = $this->db->getPdo()->prepare("
            UPDATE tenant_quotas
            SET current_usage = 0, updated_at = CURRENT_TIMESTAMP
            WHERE tenant_id = ? AND metric_key = ?
        ");
        $stmt->execute([$tid, $metricKey]);
    }
}
