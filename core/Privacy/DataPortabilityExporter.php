<?php

declare(strict_types=1);

namespace Core\Privacy;

use Core\Database\Connection;
use InvalidArgumentException;

class DataPortabilityExporter
{
    private Connection $db;

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

        // 1. Settings & Active Modules
        $data['tenant'] = $tenant;
        $data['settings'] = $this->queryTenantTable('tenant_settings', $tenantId);
        $data['active_modules'] = $this->queryTenantTable('tenant_modules', $tenantId);

        // 2. Users (via tenant_user pivot)
        $users = $this->db->select(
            "SELECT u.id, u.name, u.email, u.created_at, tu.role as tenant_role 
             FROM users u 
             INNER JOIN tenant_user tu ON tu.user_id = u.id 
             WHERE tu.tenant_id = :id",
            ['id' => $tenantId]
        );
        $data['users'] = $users;

        // 3. Roles and Permissions
        $data['roles'] = $this->queryTenantTable('roles', $tenantId);
        $data['role_permissions'] = $this->queryTenantTable('role_permissions', $tenantId);

        // 4. Contacts (Patients, Staff, Vendors, Partners)
        $data['contacts'] = $this->queryTenantTable('contacts', $tenantId);

        // 5. Finance & Ledgers
        $data['financial_accounts'] = $this->queryTenantTable('financial_accounts', $tenantId);
        $data['financial_categories'] = $this->queryTenantTable('financial_categories', $tenantId);
        $data['financial_transactions'] = $this->queryTenantTable('financial_transactions', $tenantId);
        $data['ledgers'] = $this->queryTenantTable('ledgers', $tenantId);
        $data['ledger_entries'] = $this->queryTenantTable('ledger_entries', $tenantId);

        // 6. Items & Inventory Multi-Warehouse
        $data['items'] = $this->queryTenantTable('items', $tenantId);
        $data['item_categories'] = $this->queryTenantTable('item_categories', $tenantId);
        $data['item_batches'] = $this->queryTenantTable('item_batches', $tenantId);
        $data['inventory_units'] = $this->queryTenantTable('inventory_units', $tenantId);
        $data['inventory_stock_locations'] = $this->queryTenantTable('inventory_stock_locations', $tenantId);
        $data['inventory_movements'] = $this->queryTenantTable('inventory_movements', $tenantId);
        $data['inventory_transfers'] = $this->queryTenantTable('inventory_transfers', $tenantId);

        // 7. Invoicing & Billing
        $data['invoices'] = $this->queryTenantTable('invoices', $tenantId);
        $data['invoice_items'] = $this->queryTenantTable('invoice_items', $tenantId);
        $data['invoice_payments'] = $this->queryTenantTable('invoice_payments', $tenantId);

        // 8. Facilities, Rooms, Spaces
        $data['facilities_locations'] = $this->queryTenantTable('facilities_locations', $tenantId);
        $data['facilities_rooms'] = $this->queryTenantTable('facilities_rooms', $tenantId);
        $data['facilities_spaces'] = $this->queryTenantTable('facilities_spaces', $tenantId);

        // 9. Scheduling, Appointments, Queues
        $data['resource_schedules'] = $this->queryTenantTable('resource_schedules', $tenantId);
        $data['appointments'] = $this->queryTenantTable('appointments', $tenantId);
        $data['queue_tickets'] = $this->queryTenantTable('queue_tickets', $tenantId);

        // 10. Tariffs & Splits
        $data['tariff_price_books'] = $this->queryTenantTable('tariff_price_books', $tenantId);
        $data['tariff_rates'] = $this->queryTenantTable('tariff_rates', $tenantId);
        $data['tariff_split_rules'] = $this->queryTenantTable('tariff_split_rules', $tenantId);
        $data['tariff_fee_allocations'] = $this->queryTenantTable('tariff_fee_allocations', $tenantId);

        // 11. Terminology (Custom Code Systems)
        $data['terminology_code_systems'] = $this->queryTenantTable('terminology_code_systems', $tenantId);
        $data['terminology_concepts'] = $this->queryTenantTable('terminology_concepts', $tenantId);
        $data['terminology_mappings'] = $this->queryTenantTable('terminology_mappings', $tenantId);

        // 12. Document Seals
        $data['document_seals'] = $this->queryTenantTable('document_seals', $tenantId);

        // 13. Audit Logs (Optional/Default included up to 5000 rows)
        if ($options['include_audits'] ?? true) {
            $data['audit_logs'] = $this->db->select(
                "SELECT * FROM audit_logs WHERE tenant_id = :id ORDER BY id DESC LIMIT 5000",
                ['id' => $tenantId]
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
     * Helper to safely query a tenant-scoped table if it exists.
     */
    private function queryTenantTable(string $table, int $tenantId): array
    {
        try {
            return $this->db->select("SELECT * FROM {$table} WHERE tenant_id = :id", ['id' => $tenantId]);
        } catch (\Throwable $e) {
            return [];
        }
    }
}
