<?php

declare(strict_types=1);

namespace Core\Privacy;

use Core\Database\Connection;
use InvalidArgumentException;
use Throwable;

class DataPortabilityExporter
{
    private Connection $db;

    /**
     * Tables explicitly excluded from portability exports for security & privacy reasons.
     */
    private const EXCLUDED_TABLES = [
        'tenant_encryption_keys',
        'locks',
        'migrations',
        'password_resets',
    ];

    /**
     * Columns whose values must be redacted to prevent credential/secret leakage in export files.
     */
    private const SENSITIVE_COLUMN_PATTERNS = [
        '/^password_hash$/i',
        '/^secret$/i',
        '/^key_salt$/i',
        '/^token$/i',
    ];

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Export all tenant data across all platform modules in deterministic JSON format.
     *
     * @param int $tenantId
     * @param array $options ['output_file' => string, 'include_audits' => bool]
     * @return array
     */
    public function exportTenant(int $tenantId, array $options = []): array
    {
        $tenant = $this->db->selectOne("SELECT * FROM tenants WHERE id = :id", ['id' => $tenantId]);
        if ($tenant === null) {
            throw new InvalidArgumentException("Tenant [{$tenantId}] not found.");
        }

        $data = [];
        $counts = [];

        // 1. Root Tenant Record & Core Settings
        $data['tenant'] = $tenant;
        $data['settings'] = $this->queryTenantTable('tenant_settings', $tenantId);
        $data['active_modules'] = $this->queryTenantTable('tenant_modules', $tenantId);

        // 2. Users (via tenant_user pivot, with password_hash excluded)
        if ($this->tableExists('users') && $this->tableExists('tenant_user')) {
            $data['users'] = $this->db->select(
                "SELECT u.id, u.name, u.email, u.created_at, tu.role as tenant_role 
                 FROM users u 
                 INNER JOIN tenant_user tu ON tu.user_id = u.id 
                 WHERE tu.tenant_id = :id",
                ['id' => $tenantId]
            );
        } else {
            $data['users'] = [];
        }

        // 3. Known Business Foundations (guaranteed keys for schema contracts)
        $knownTenantTables = [
            'roles',
            'role_permissions',
            'contacts',
            'financial_accounts',
            'financial_categories',
            'financial_transactions',
            'ledgers',
            'ledger_entries',
            'items',
            'item_categories',
            'item_batches',
            'inventory_units',
            'inventory_stock_locations',
            'inventory_movements',
            'inventory_transfers',
            'invoices',
            'invoice_items',
            'invoice_payments',
            'facilities_locations',
            'facilities_rooms',
            'facilities_spaces',
            'resource_schedules',
            'appointments',
            'queue_tickets',
            'tariff_price_books',
            'tariff_rates',
            'tariff_split_rules',
            'tariff_fee_allocations',
            'terminology_code_systems',
            'terminology_concepts',
            'terminology_mappings',
            'document_seals',
        ];

        foreach ($knownTenantTables as $tbl) {
            $data[$tbl] = $this->queryTenantTable($tbl, $tenantId);
        }

        // 4. Export dependent child leaf tables without direct tenant_id
        if ($this->tableExists('contact_addresses') && $this->tableExists('contacts')) {
            $data['contact_addresses'] = $this->sanitizeRows(
                $this->db->select(
                    "SELECT * FROM contact_addresses WHERE contact_id IN (SELECT id FROM contacts WHERE tenant_id = :id)",
                    ['id' => $tenantId]
                )
            );
        }

        if ($this->tableExists('contact_roles') && $this->tableExists('contacts')) {
            $data['contact_roles'] = $this->sanitizeRows(
                $this->db->select(
                    "SELECT * FROM contact_roles WHERE contact_id IN (SELECT id FROM contacts WHERE tenant_id = :id)",
                    ['id' => $tenantId]
                )
            );
        }

        if ($this->tableExists('inventory_transfer_items') && $this->tableExists('inventory_transfers')) {
            $data['inventory_transfer_items'] = $this->sanitizeRows(
                $this->db->select(
                    "SELECT * FROM inventory_transfer_items WHERE transfer_id IN (SELECT id FROM inventory_transfers WHERE tenant_id = :id)",
                    ['id' => $tenantId]
                )
            );
        }

        // 5. Discover any dynamically migrated tenant tables not yet in known list
        $dynamicTables = $this->discoverDynamicTenantTables();
        foreach ($dynamicTables as $tbl) {
            if (!isset($data[$tbl]) && !in_array($tbl, self::EXCLUDED_TABLES, true)) {
                $data[$tbl] = $this->queryTenantTable($tbl, $tenantId);
            }
        }

        // 6. Audit Logs (Optional / Default included up to 5000 rows)
        if (($options['include_audits'] ?? true) && $this->tableExists('audit_logs')) {
            $data['audit_logs'] = $this->sanitizeRows(
                $this->db->select(
                    "SELECT * FROM audit_logs WHERE tenant_id = :id ORDER BY id DESC LIMIT 5000",
                    ['id' => $tenantId]
                )
            );
        }

        // Calculate counts
        foreach ($data as $key => $records) {
            $counts[$key] = is_array($records) ? (isset($records['id']) ? 1 : count($records)) : 0;
        }

        $manifest = [
            'format' => 'aio-portability-v1',
            'exported_at' => date('c'),
            'tenant_id' => $tenantId,
            'tenant_name' => $tenant['name'] ?? 'Unknown Tenant',
            'tenant_slug' => $tenant['slug'] ?? 'unknown',
            'entity_counts' => $counts,
            'total_entities' => array_sum($counts),
        ];

        $exportEnvelope = [
            'manifest' => $manifest,
            'data' => $data,
        ];

        $serialized = (string) json_encode($exportEnvelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $digest = hash('sha256', $serialized);
        $exportEnvelope['manifest']['checksum_sha256'] = $digest;

        // If output file specified, write to disk
        if (isset($options['output_file']) && is_string($options['output_file'])) {
            $filePath = $options['output_file'];
            $dir = dirname($filePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($filePath, $serialized);
            $exportEnvelope['manifest']['file_path'] = $filePath;
            $exportEnvelope['manifest']['file_size_bytes'] = strlen($serialized);
        }

        return $exportEnvelope;
    }

    /**
     * Helper to safely query a tenant-scoped table if it exists and redact sensitive columns.
     */
    private function queryTenantTable(string $table, int $tenantId): array
    {
        if (in_array($table, self::EXCLUDED_TABLES, true)) {
            return [];
        }

        try {
            $rows = $this->db->select("SELECT * FROM {$table} WHERE tenant_id = :id", ['id' => $tenantId]);
            return $this->sanitizeRows($rows);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Redact sensitive credentials/tokens/secrets from row sets.
     */
    private function sanitizeRows(array $rows): array
    {
        return array_map(function ($row) {
            if (!is_array($row)) {
                return $row;
            }
            foreach ($row as $col => $val) {
                foreach (self::SENSITIVE_COLUMN_PATTERNS as $pattern) {
                    if (preg_match($pattern, (string) $col) && $val !== null && $val !== '') {
                        $row[$col] = '[REDACTED]';
                        break;
                    }
                }
            }
            return $row;
        }, $rows);
    }

    private function discoverDynamicTenantTables(): array
    {
        try {
            $rows = $this->db->selectAll("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
            $tables = [];
            foreach ($rows as $r) {
                $tableName = (string) $r['name'];
                if ($tableName === 'tenants' || in_array($tableName, self::EXCLUDED_TABLES, true)) {
                    continue;
                }
                if ($this->db->hasColumn($tableName, 'tenant_id')) {
                    $tables[] = $tableName;
                }
            }
            return $tables;
        } catch (Throwable) {
            return [];
        }
    }

    private function tableExists(string $tableName): bool
    {
        try {
            $row = $this->db->selectOne(
                "SELECT name FROM sqlite_master WHERE type='table' AND name = :name",
                ['name' => $tableName]
            );
            return $row !== null;
        } catch (Throwable) {
            return false;
        }
    }
}
