<?php

declare(strict_types=1);

namespace Core\Privacy;

use Core\Database\Connection;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class TenantDataShredder
{
    private Connection $db;
    private TenantKeyManager $keyManager;
    private string $masterKey;

    /**
     * Preferred purge order: leaf/child tables before parent/root tables.
     */
    private const PREFERRED_PURGE_ORDER = [
        'api_tokens',
        'document_verification_logs',
        'document_seals',
        'queue_tickets',
        'appointments',
        'resource_schedules',
        'facilities_spaces',
        'facilities_rooms',
        'facilities_locations',
        'tariff_fee_allocations',
        'tariff_split_rules',
        'tariff_rates',
        'tariff_price_books',
        'invoice_payments',
        'invoice_items',
        'invoices',
        'inventory_transfer_items',
        'inventory_transfers',
        'inventory_stock_locations',
        'inventory_movements',
        'item_batches',
        'items',
        'item_categories',
        'inventory_units',
        'ledger_entries',
        'ledgers',
        'financial_transactions',
        'financial_accounts',
        'financial_categories',
        'contact_addresses',
        'contact_roles',
        'contact_relations',
        'contacts',
        'terminology_mappings',
        'terminology_concepts',
        'terminology_code_systems',
        'reconciliation_audit_logs',
        'outbox_events',
        'sse_events',
        'webhook_deliveries',
        'webhooks',
        'notifications',
        'tasks',
        'projects',
        'vector_embeddings',
        'workspace_invitations',
        'role_permissions',
        'roles',
        'sequences',
        'failed_jobs',
        'jobs',
        'files',
        'tenant_settings',
        'tenant_modules',
        'tenant_encryption_keys',
        'tenant_user',
        'audit_logs',
    ];

    public function __construct(Connection $db, TenantKeyManager $keyManager, ?string $masterKey = null)
    {
        $this->db = $db;
        $this->keyManager = $keyManager;
        $this->masterKey = $masterKey ?? \Core\Security\AppKey::get();
    }

    /**
     * Dynamically discover all tables in SQLite that contain a tenant_id column.
     *
     * @return array<int, string>
     */
    public function getTenantTables(): array
    {
        $rows = $this->db->selectAll("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        $tablesWithTenantId = [];

        foreach ($rows as $r) {
            $tableName = (string) $r['name'];
            if ($tableName === 'tenants') {
                continue;
            }
            if ($this->hasColumn($tableName, 'tenant_id')) {
                $tablesWithTenantId[] = $tableName;
            }
        }

        // Sort tables according to preferred leaf-to-parent order
        $ordered = [];
        foreach (self::PREFERRED_PURGE_ORDER as $orderedTable) {
            if (in_array($orderedTable, $tablesWithTenantId, true)) {
                $ordered[] = $orderedTable;
            }
        }

        // Add any newly migrated tenant tables that are not in the predefined list
        foreach ($tablesWithTenantId as $table) {
            if (!in_array($table, $ordered, true)) {
                array_unshift($ordered, $table);
            }
        }

        return $ordered;
    }

    /**
     * Anonymize personally identifiable information (PII) for the tenant.
     * Complies with GDPR / UU PDP Article 17 "Right to Erasure" while preserving
     * financial ledger and accounting records for statutory audit compliance.
     */
    public function anonymizeTenant(int $tenantId, ?string $reason = null): array
    {
        $tenant = $this->db->selectOne("SELECT * FROM tenants WHERE id = :id", ['id' => $tenantId]);
        if ($tenant === null) {
            throw new InvalidArgumentException("Tenant [{$tenantId}] not found.");
        }

        $now = date('c');

        return $this->db->transaction(function () use ($tenantId, $tenant, $now, $reason) {
            $anonymizedCounts = [];

            // 1. Anonymize Contacts & Child Tables (Addresses, Roles, Relations)
            if ($this->tableExists('contacts')) {
                $contacts = $this->db->select("SELECT id FROM contacts WHERE tenant_id = :id", ['id' => $tenantId]);
                $contactCols = $this->getColumnNames('contacts');

                foreach ($contacts as $contact) {
                    $cid = (int) $contact['id'];
                    $anonHash = substr(hash('sha256', "contact-{$cid}-{$now}"), 0, 8);

                    $updates = ["name = :anon_name"];
                    $bindings = [
                        'anon_name' => "ANON_CONTACT_{$anonHash}",
                        'cid' => $cid,
                    ];

                    $nullCols = [
                        'legal_name', 'id_number', 'tax_id', 'email', 'phone',
                        'mobile', 'website', 'notes', 'address'
                    ];

                    foreach ($nullCols as $col) {
                        if (in_array($col, $contactCols, true)) {
                            $updates[] = "{$col} = NULL";
                        }
                    }

                    $this->db->statement(
                        "UPDATE contacts SET " . implode(', ', $updates) . " WHERE id = :cid",
                        $bindings
                    );
                }
                $anonymizedCounts['contacts'] = count($contacts);

                // Scrub child contact tables
                if ($this->tableExists('contact_addresses')) {
                    $this->db->statement(
                        "DELETE FROM contact_addresses WHERE contact_id IN (SELECT id FROM contacts WHERE tenant_id = :id)",
                        ['id' => $tenantId]
                    );
                }
                if ($this->tableExists('contact_roles') && $this->hasColumn('contact_roles', 'metadata')) {
                    $this->db->statement(
                        "UPDATE contact_roles SET metadata = NULL WHERE contact_id IN (SELECT id FROM contacts WHERE tenant_id = :id)",
                        ['id' => $tenantId]
                    );
                }
                if ($this->tableExists('contact_relations')) {
                    $this->db->statement(
                        "DELETE FROM contact_relations WHERE primary_contact_id IN (SELECT id FROM contacts WHERE tenant_id = :id) OR related_contact_id IN (SELECT id FROM contacts WHERE tenant_id = :id)",
                        ['id' => $tenantId]
                    );
                }
            }

            // 2. Anonymize Users (only if user does not belong to other active tenants and is not superadmin)
            if ($this->tableExists('users') && $this->tableExists('tenant_user')) {
                $users = $this->db->select(
                    "SELECT u.id, u.is_superadmin 
                     FROM users u 
                     INNER JOIN tenant_user tu ON tu.user_id = u.id 
                     WHERE tu.tenant_id = :id",
                    ['id' => $tenantId]
                );

                $anonymizedUserCount = 0;
                $userCols = $this->getColumnNames('users');

                foreach ($users as $user) {
                    if ((int) ($user['is_superadmin'] ?? 0) === 1) {
                        continue; // Never touch global superadmin
                    }

                    $uid = (int) $user['id'];
                    $otherTenants = $this->db->selectOne(
                        "SELECT COUNT(*) as count FROM tenant_user WHERE user_id = :uid AND tenant_id != :tid",
                        ['uid' => $uid, 'tid' => $tenantId]
                    );

                    if ((int) ($otherTenants['count'] ?? 0) === 0) {
                        $anonHash = substr(hash('sha256', "user-{$uid}-{$now}"), 0, 8);
                        $updates = [];
                        $bindings = ['uid' => $uid];

                        if (in_array('name', $userCols, true)) {
                            $updates[] = "name = :anon_name";
                            $bindings['anon_name'] = "ANON_USER_{$anonHash}";
                        }
                        if (in_array('email', $userCols, true)) {
                            $updates[] = "email = :anon_email";
                            $bindings['anon_email'] = "anon_{$anonHash}@shredded.local";
                        }
                        if (in_array('password_hash', $userCols, true)) {
                            $updates[] = "password_hash = ''";
                        }

                        if (!empty($updates)) {
                            $this->db->statement(
                                "UPDATE users SET " . implode(', ', $updates) . " WHERE id = :uid",
                                $bindings
                            );
                            $anonymizedUserCount++;
                        }
                    }
                }
                $anonymizedCounts['users'] = $anonymizedUserCount;
            }

            // 3. Clear personal notes and details from Appointments
            if ($this->tableExists('appointments') && $this->hasColumn('appointments', 'notes')) {
                $this->db->statement(
                    "UPDATE appointments SET notes = NULL WHERE tenant_id = :id",
                    ['id' => $tenantId]
                );
            }

            // 4. Clear notes and details from Invoices
            if ($this->tableExists('invoices') && $this->hasColumn('invoices', 'notes')) {
                $this->db->statement(
                    "UPDATE invoices SET notes = NULL WHERE tenant_id = :id",
                    ['id' => $tenantId]
                );
            }

            // 5. Clear client name and notes from Queue Tickets
            if ($this->tableExists('queue_tickets')) {
                $qtUpdates = [];
                if ($this->hasColumn('queue_tickets', 'client_name')) {
                    $qtUpdates[] = "client_name = NULL";
                }
                if ($this->hasColumn('queue_tickets', 'notes')) {
                    $qtUpdates[] = "notes = NULL";
                }
                if (!empty($qtUpdates)) {
                    $this->db->statement(
                        "UPDATE queue_tickets SET " . implode(', ', $qtUpdates) . " WHERE tenant_id = :id",
                        ['id' => $tenantId]
                    );
                }
            }

            // 6. Anonymize Document Seals subject identifiers
            if ($this->tableExists('document_seals')) {
                $sealUpdates = [];
                if ($this->hasColumn('document_seals', 'subject_name')) {
                    $sealUpdates[] = "subject_name = 'ANONYMIZED'";
                }
                if ($this->hasColumn('document_seals', 'subject_identifier')) {
                    $sealUpdates[] = "subject_identifier = NULL";
                }
                if (!empty($sealUpdates)) {
                    $this->db->statement(
                        "UPDATE document_seals SET " . implode(', ', $sealUpdates) . " WHERE tenant_id = :id",
                        ['id' => $tenantId]
                    );
                }
            }

            // 7. Crypto-shred the tenant cryptographic key
            $this->keyManager->shredKey($tenantId);

            return [
                'status' => 'anonymized',
                'tenant_id' => $tenantId,
                'tenant_name' => $tenant['name'],
                'anonymized_at' => $now,
                'reason' => $reason ?? 'Right to Erasure (UU PDP / GDPR)',
                'crypto_key_shredded' => true,
                'anonymized_entities' => $anonymizedCounts,
            ];
        });
    }

    /**
     * Completely and permanently shred all physical records of the tenant.
     * Issues a Cryptographic Certificate of Destruction upon completion.
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function shredTenant(int $tenantId, bool $force = false, ?string $reason = null): array
    {
        if (!$force) {
            throw new InvalidArgumentException("Force confirmation is required to permanently shred tenant [{$tenantId}].");
        }

        $tenant = $this->db->selectOne("SELECT * FROM tenants WHERE id = :id", ['id' => $tenantId]);
        if ($tenant === null) {
            throw new InvalidArgumentException("Tenant [{$tenantId}] not found.");
        }

        $now = date('c');
        $certificateId = 'CERT-SHRED-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));

        // 1. Discover all tenant-scoped tables and count records before purging
        $tenantTables = $this->getTenantTables();
        $purgedCounts = [];

        foreach ($tenantTables as $table) {
            try {
                $row = $this->db->selectOne("SELECT COUNT(*) as count FROM {$table} WHERE tenant_id = :id", ['id' => $tenantId]);
                $purgedCounts[$table] = (int) ($row['count'] ?? 0);
            } catch (Throwable) {
                $purgedCounts[$table] = 0;
            }
        }

        // Count dependent child tables that lack a direct tenant_id
        $childCounts = $this->countDependentChildRecords($tenantId);
        foreach ($childCounts as $childTable => $cnt) {
            $purgedCounts[$childTable] = $cnt;
        }

        $totalRowsPurged = array_sum($purgedCounts);

        // 2. Execute Transactional Cascading Purge with Foreign Keys Enforced
        $this->db->statement("PRAGMA foreign_keys = ON;");

        $this->db->transaction(function () use ($tenantId, $tenantTables) {
            // Defer foreign key constraints until transaction commits to allow orderly cascading
            $this->db->statement("PRAGMA defer_foreign_keys = ON;");

            // Purge child leaf tables without direct tenant_id first
            $this->purgeDependentChildRecords($tenantId);

            // Shred encryption key first to destroy key material and clear in-memory cache
            $this->keyManager->shredKey($tenantId);

            // Purge all tenant-scoped tables in order (including tenant_encryption_keys)
            foreach ($tenantTables as $table) {
                if ($this->tableExists($table)) {
                    $this->db->statement("DELETE FROM {$table} WHERE tenant_id = :id", ['id' => $tenantId]);
                }
            }

            // Remove tenant record itself
            $this->db->statement("DELETE FROM tenants WHERE id = :id", ['id' => $tenantId]);
        });

        // 3. Post-destruction verification: verify 0 records remain
        foreach ($tenantTables as $table) {
            if ($this->tableExists($table)) {
                $remaining = (int) ($this->db->selectOne("SELECT COUNT(*) as count FROM {$table} WHERE tenant_id = :id", ['id' => $tenantId])['count'] ?? 0);
                if ($remaining > 0) {
                    throw new RuntimeException("Destruction verification failed: {$remaining} records remain in table [{$table}] for tenant [{$tenantId}].");
                }
            }
        }

        // 4. Generate Cryptographic Certificate of Destruction AFTER verified complete purge
        $certificate = [
            'certificate_id' => $certificateId,
            'tenant_id' => $tenantId,
            'tenant_name' => $tenant['name'],
            'tenant_slug' => $tenant['slug'] ?? 'unknown',
            'shredded_at' => $now,
            'reason' => $reason ?? 'Tenant requested permanent data destruction',
            'total_rows_purged' => $totalRowsPurged,
            'purged_tables' => array_filter($purgedCounts, fn($cnt) => $cnt > 0),
        ];

        $canonicalJson = (string) json_encode($certificate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signature = hash_hmac('sha256', $canonicalJson, $this->masterKey);
        $certificate['destruction_signature'] = $signature;

        return [
            'status' => 'shredded',
            'tenant_id' => $tenantId,
            'certificate' => $certificate,
        ];
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

    private function hasColumn(string $tableName, string $columnName): bool
    {
        return $this->db->hasColumn($tableName, $columnName);
    }

    /**
     * @return array<int, string>
     */
    private function getColumnNames(string $tableName): array
    {
        return $this->db->getTableColumns($tableName);
    }

    /**
     * @return array<string, int>
     */
    private function countDependentChildRecords(int $tenantId): array
    {
        $counts = [];

        if ($this->tableExists('contact_addresses') && $this->tableExists('contacts')) {
            $row = $this->db->selectOne(
                "SELECT COUNT(*) as cnt FROM contact_addresses WHERE contact_id IN (SELECT id FROM contacts WHERE tenant_id = :id)",
                ['id' => $tenantId]
            );
            $counts['contact_addresses'] = (int) ($row['cnt'] ?? 0);
        }

        if ($this->tableExists('contact_roles') && $this->tableExists('contacts')) {
            $row = $this->db->selectOne(
                "SELECT COUNT(*) as cnt FROM contact_roles WHERE contact_id IN (SELECT id FROM contacts WHERE tenant_id = :id)",
                ['id' => $tenantId]
            );
            $counts['contact_roles'] = (int) ($row['cnt'] ?? 0);
        }

        if ($this->tableExists('contact_relations') && $this->tableExists('contacts')) {
            $row = $this->db->selectOne(
                "SELECT COUNT(*) as cnt FROM contact_relations WHERE primary_contact_id IN (SELECT id FROM contacts WHERE tenant_id = :id) OR related_contact_id IN (SELECT id FROM contacts WHERE tenant_id = :id)",
                ['id' => $tenantId]
            );
            $counts['contact_relations'] = (int) ($row['cnt'] ?? 0);
        }

        if ($this->tableExists('inventory_transfer_items') && $this->tableExists('inventory_transfers')) {
            $row = $this->db->selectOne(
                "SELECT COUNT(*) as cnt FROM inventory_transfer_items WHERE transfer_id IN (SELECT id FROM inventory_transfers WHERE tenant_id = :id)",
                ['id' => $tenantId]
            );
            $counts['inventory_transfer_items'] = (int) ($row['cnt'] ?? 0);
        }

        if ($this->tableExists('document_verification_logs') && $this->tableExists('document_seals')) {
            $row = $this->db->selectOne(
                "SELECT COUNT(*) as cnt FROM document_verification_logs WHERE document_seal_id IN (SELECT id FROM document_seals WHERE tenant_id = :id)",
                ['id' => $tenantId]
            );
            $counts['document_verification_logs'] = (int) ($row['cnt'] ?? 0);
        }

        return $counts;
    }

    private function purgeDependentChildRecords(int $tenantId): void
    {
        if ($this->tableExists('contact_addresses') && $this->tableExists('contacts')) {
            $this->db->statement(
                "DELETE FROM contact_addresses WHERE contact_id IN (SELECT id FROM contacts WHERE tenant_id = :id)",
                ['id' => $tenantId]
            );
        }

        if ($this->tableExists('contact_roles') && $this->tableExists('contacts')) {
            $this->db->statement(
                "DELETE FROM contact_roles WHERE contact_id IN (SELECT id FROM contacts WHERE tenant_id = :id)",
                ['id' => $tenantId]
            );
        }

        if ($this->tableExists('contact_relations') && $this->tableExists('contacts')) {
            $this->db->statement(
                "DELETE FROM contact_relations WHERE primary_contact_id IN (SELECT id FROM contacts WHERE tenant_id = :id) OR related_contact_id IN (SELECT id FROM contacts WHERE tenant_id = :id)",
                ['id' => $tenantId]
            );
        }

        if ($this->tableExists('inventory_transfer_items') && $this->tableExists('inventory_transfers')) {
            $this->db->statement(
                "DELETE FROM inventory_transfer_items WHERE transfer_id IN (SELECT id FROM inventory_transfers WHERE tenant_id = :id)",
                ['id' => $tenantId]
            );
        }

        if ($this->tableExists('document_verification_logs') && $this->tableExists('document_seals')) {
            $this->db->statement(
                "DELETE FROM document_verification_logs WHERE document_seal_id IN (SELECT id FROM document_seals WHERE tenant_id = :id)",
                ['id' => $tenantId]
            );
        }
    }
}
