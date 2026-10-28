<?php

declare(strict_types=1);

namespace Core\Privacy;

use Core\Database\Connection;
use InvalidArgumentException;

class TenantDataShredder
{
    private Connection $db;
    private TenantKeyManager $keyManager;
    private string $masterKey;

    /**
     * List of tenant-scoped tables to purge during hard shredding.
     * Ordered from child/leaf tables to parent tables.
     */
    private const TENANT_TABLES = [
        'api_tokens',
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
        $this->masterKey = $masterKey ?? (string) (config('app.key') ?: env('APP_KEY', 'aio-universal-secret-key-32chars!!'));
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
        $anonymizedCounts = [];

        return $this->db->transaction(function () use ($tenantId, $tenant, $now, $reason) {
            $anonymizedCounts = [];

            // 1. Anonymize Contacts
            $contacts = $this->db->select("SELECT id FROM contacts WHERE tenant_id = :id", ['id' => $tenantId]);
            foreach ($contacts as $contact) {
                $cid = (int) $contact['id'];
                $anonHash = substr(hash('sha256', "contact-{$cid}-{$now}"), 0, 8);
                $this->db->statement(
                    "UPDATE contacts 
                     SET name = :anon_name, email = NULL, phone = NULL, address = NULL, notes = NULL 
                     WHERE id = :cid",
                    [
                        'anon_name' => "ANON_CONTACT_{$anonHash}",
                        'cid' => $cid,
                    ]
                );
            }
            $anonymizedCounts['contacts'] = count($contacts);

            // 2. Anonymize Users (only if user does not belong to other active tenants and is not superadmin)
            $users = $this->db->select(
                "SELECT u.id, u.is_superadmin 
                 FROM users u 
                 INNER JOIN tenant_user tu ON tu.user_id = u.id 
                 WHERE tu.tenant_id = :id",
                ['id' => $tenantId]
            );

            $anonymizedUserCount = 0;
            foreach ($users as $user) {
                if ((int) ($user['is_superadmin'] ?? 0) === 1) {
                    continue; // Never touch global superadmin
                }

                $uid = (int) $user['id'];
                // Check other tenant memberships
                $otherTenants = $this->db->selectOne(
                    "SELECT COUNT(*) as count FROM tenant_user WHERE user_id = :uid AND tenant_id != :tid",
                    ['uid' => $uid, 'tid' => $tenantId]
                );

                if ((int) ($otherTenants['count'] ?? 0) === 0) {
                    $anonHash = substr(hash('sha256', "user-{$uid}-{$now}"), 0, 8);
                    $this->db->statement(
                        "UPDATE users 
                         SET name = :anon_name, email = :anon_email, password_hash = '' 
                         WHERE id = :uid",
                        [
                            'anon_name' => "ANON_USER_{$anonHash}",
                            'anon_email' => "anon_{$anonHash}@shredded.local",
                            'uid' => $uid,
                        ]
                    );
                    $anonymizedUserCount++;
                }
            }
            $anonymizedCounts['users'] = $anonymizedUserCount;

            // 3. Clear personal notes and details from Appointments
            $this->db->statement(
                "UPDATE appointments SET notes = NULL WHERE tenant_id = :id",
                ['id' => $tenantId]
            );

            // 4. Clear notes and details from Invoices
            $this->db->statement(
                "UPDATE invoices SET notes = NULL WHERE tenant_id = :id",
                ['id' => $tenantId]
            );

            // 5. Crypto-shred the tenant cryptographic key (making all past encrypted payloads unrecoverable)
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

        // 1. Audit pre-shred record counts
        $purgedCounts = [];
        foreach (self::TENANT_TABLES as $table) {
            try {
                $row = $this->db->selectOne("SELECT COUNT(*) as count FROM {$table} WHERE tenant_id = :id", ['id' => $tenantId]);
                $purgedCounts[$table] = (int) ($row['count'] ?? 0);
            } catch (\Throwable $e) {
                // Table might not exist in current migration state
            }
        }

        $totalRowsPurged = array_sum($purgedCounts);

        // 2. Generate Cryptographic Certificate of Destruction
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

        // 3. Execute Transactional Cascading Purge
        $this->db->statement("PRAGMA foreign_keys = OFF;");

        try {
            $this->db->transaction(function () use ($tenantId) {
                foreach (self::TENANT_TABLES as $table) {
                    try {
                        $this->db->statement("DELETE FROM {$table} WHERE tenant_id = :id", ['id' => $tenantId]);
                    } catch (\Throwable $e) {
                        // Ignore non-existent tables
                    }
                }

                // Remove tenant record itself
                $this->db->statement("DELETE FROM tenants WHERE id = :id", ['id' => $tenantId]);

                // Shred encryption key
                $this->keyManager->shredKey($tenantId);
            });
        } finally {
            $this->db->statement("PRAGMA foreign_keys = ON;");
        }

        $this->db->statement("PRAGMA foreign_keys = ON;");

        return [
            'status' => 'shredded',
            'tenant_id' => $tenantId,
            'certificate' => $certificate,
        ];
    }
}
