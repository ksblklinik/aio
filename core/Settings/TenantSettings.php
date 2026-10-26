<?php

declare(strict_types=1);

namespace Core\Settings;

use Core\Database\Connection;
use Core\Database\TenantContext;
use RuntimeException;

class TenantSettings
{
    /**
     * @var array<string, mixed> In-memory cache keyed by "tenant_{tenantId}_{key}"
     */
    protected array $cache = [];

    /**
     * @var array<int, array<string, mixed>> In-memory cache for tenant profiles
     */
    protected array $profileCache = [];

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

        throw new RuntimeException('TenantSettings requires an active TenantContext or explicit tenantId.');
    }

    /**
     * Get a setting value for the given key and tenant.
     */
    public function get(string $key, mixed $default = null, ?int $tenantId = null): mixed
    {
        try {
            $tId = $this->resolveTenantId($tenantId);
        } catch (RuntimeException) {
            return $default;
        }

        $cacheKey = "{$tId}:{$key}";
        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $row = $this->db->selectOne(
            "SELECT value, type FROM tenant_settings WHERE tenant_id = ? AND key = ? LIMIT 1",
            [$tId, $key]
        );

        if (!$row) {
            $this->cache[$cacheKey] = $default;
            return $default;
        }

        $val = $this->castValueFromType($row['value'], (string) ($row['type'] ?? 'string'));
        $this->cache[$cacheKey] = $val;

        return $val;
    }

    /**
     * Set a setting value for the given key and tenant.
     */
    public function set(string $key, mixed $value, string $module = 'core', ?int $tenantId = null): void
    {
        $tId = $this->resolveTenantId($tenantId);

        [$storedValue, $type] = $this->determineStorageType($value);

        $sql = "INSERT INTO tenant_settings (tenant_id, module, key, value, type, updated_at)
                VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                ON CONFLICT(tenant_id, module, key) DO UPDATE SET
                    value = excluded.value,
                    type = excluded.type,
                    updated_at = CURRENT_TIMESTAMP";

        $this->db->statement($sql, [$tId, $module, $key, $storedValue, $type]);

        $this->cache["{$tId}:{$key}"] = $value;
    }

    /**
     * Check if a setting exists and is not null.
     */
    public function has(string $key, ?int $tenantId = null): bool
    {
        return $this->get($key, null, $tenantId) !== null;
    }

    /**
     * Forget / delete a setting.
     */
    public function forget(string $key, ?int $tenantId = null): void
    {
        $tId = $this->resolveTenantId($tenantId);

        $this->db->statement(
            "DELETE FROM tenant_settings WHERE tenant_id = ? AND key = ?",
            [$tId, $key]
        );

        unset($this->cache["{$tId}:{$key}"]);
    }

    /**
     * Retrieve all settings as key-value pairs for a tenant.
     *
     * @return array<string, mixed>
     */
    public function all(?string $module = null, ?int $tenantId = null): array
    {
        $tId = $this->resolveTenantId($tenantId);

        if ($module !== null) {
            $rows = $this->db->selectAll(
                "SELECT key, value, type FROM tenant_settings WHERE tenant_id = ? AND module = ?",
                [$tId, $module]
            );
        } else {
            $rows = $this->db->selectAll(
                "SELECT key, value, type FROM tenant_settings WHERE tenant_id = ?",
                [$tId]
            );
        }

        $results = [];
        foreach ($rows as $row) {
            $key = (string) $row['key'];
            $val = $this->castValueFromType($row['value'], (string) ($row['type'] ?? 'string'));
            $results[$key] = $val;
            $this->cache["{$tId}:{$key}"] = $val;
        }

        return $results;
    }

    /**
     * Get organization profile (org_type, timezone, currency, locale) for a tenant.
     *
     * @return array<string, mixed>
     */
    public function getTenantProfile(?int $tenantId = null): array
    {
        $tId = $this->resolveTenantId($tenantId);

        if (isset($this->profileCache[$tId])) {
            return $this->profileCache[$tId];
        }

        $row = $this->db->selectOne(
            "SELECT id, name, slug, plan, org_type, timezone, currency, locale FROM tenants WHERE id = ?",
            [$tId]
        );

        if (!$row) {
            return [
                'id' => $tId,
                'name' => '',
                'org_type' => 'general',
                'timezone' => 'UTC',
                'currency' => 'USD',
                'locale' => 'en_US',
            ];
        }

        $profile = [
            'id' => (int) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'plan' => (string) ($row['plan'] ?? 'pro'),
            'org_type' => (string) ($row['org_type'] ?? 'general'),
            'timezone' => (string) ($row['timezone'] ?? 'UTC'),
            'currency' => (string) ($row['currency'] ?? 'USD'),
            'locale' => (string) ($row['locale'] ?? 'en_US'),
        ];

        $this->profileCache[$tId] = $profile;

        return $profile;
    }

    /**
     * Update organization profile fields for a tenant.
     *
     * @param array<string, mixed> $data
     */
    public function updateTenantProfile(array $data, ?int $tenantId = null): void
    {
        $tId = $this->resolveTenantId($tenantId);

        $allowedFields = ['name', 'org_type', 'timezone', 'currency', 'locale'];
        $updates = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "{$field} = ?";
                $params[] = (string) $data[$field];
            }
        }

        if (empty($updates)) {
            return;
        }

        $params[] = $tId;
        $sql = "UPDATE tenants SET " . implode(', ', $updates) . " WHERE id = ?";
        $this->db->statement($sql, $params);

        unset($this->profileCache[$tId]);
    }

    /**
     * Clear all cached settings and profiles in memory.
     */
    public function clearCache(): void
    {
        $this->cache = [];
        $this->profileCache = [];
    }

    /**
     * Cast raw database value string back to appropriate PHP type.
     */
    protected function castValueFromType(mixed $raw, string $type): mixed
    {
        if ($raw === null) {
            return null;
        }

        return match ($type) {
            'boolean', 'bool' => $raw === '1' || $raw === 'true' || $raw === true,
            'integer', 'int' => (int) $raw,
            'float', 'double' => (float) $raw,
            'json', 'array' => json_decode((string) $raw, true),
            default => (string) $raw,
        };
    }

    /**
     * Determine storage string and type label for saving.
     *
     * @return array{0: ?string, 1: string}
     */
    protected function determineStorageType(mixed $value): array
    {
        if ($value === null) {
            return [null, 'string'];
        }

        if (is_bool($value)) {
            return [$value ? '1' : '0', 'boolean'];
        }

        if (is_int($value)) {
            return [(string) $value, 'integer'];
        }

        if (is_float($value)) {
            return [(string) $value, 'float'];
        }

        if (is_array($value) || is_object($value)) {
            return [json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'json'];
        }

        return [(string) $value, 'string'];
    }
}
