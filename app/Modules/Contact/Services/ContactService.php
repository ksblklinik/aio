<?php

declare(strict_types=1);

namespace App\Modules\Contact\Services;

use App\Modules\Contact\Contracts\ContactRegistryInterface;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Models\ContactAddress;
use App\Modules\Contact\Models\ContactRelation;
use App\Modules\Contact\Models\ContactRole;
use Core\Database\Connection;
use Core\Database\TenantContext;
use RuntimeException;

class ContactService implements ContactRegistryInterface
{
    public function __construct(
        private Connection $db,
        private TenantContext $tenantContext
    ) {
    }

    private function getTenantId(): int
    {
        if (!$this->tenantContext->hasTenant()) {
            throw new RuntimeException("TenantContext is required to operate on contacts.");
        }
        return (int) $this->tenantContext->getTenantId();
    }

    public function create(array $attributes): Contact
    {
        $tenantId = $this->getTenantId();
        $type = (string) ($attributes['type'] ?? 'individual');
        $name = trim((string) ($attributes['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException("Contact name is required.");
        }

        // Auto-generate code if empty
        $code = trim((string) ($attributes['code'] ?? ''));
        if ($code === '') {
            $prefix = $type === 'organization' ? 'ORG' : 'CNT';
            if (function_exists('sequence')) {
                $code = sequence('contact', $prefix, 5, 'yearly');
            } else {
                $code = $prefix . '-' . date('Y') . '-' . str_pad((string) mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
            }
        }

        // Enkripsi data sensitif (PII)
        $idNumber = !empty($attributes['id_number']) ? encrypt((string) $attributes['id_number']) : null;
        $taxId = !empty($attributes['tax_id']) ? encrypt((string) $attributes['tax_id']) : null;

        $now = date('Y-m-d H:i:s');
        $contactId = (int) $this->db->insert('contacts', [
            'tenant_id' => $tenantId,
            'type' => $type,
            'code' => $code,
            'name' => $name,
            'legal_name' => $attributes['legal_name'] ?? null,
            'id_number' => $idNumber,
            'tax_id' => $taxId,
            'email' => $attributes['email'] ?? null,
            'phone' => $attributes['phone'] ?? null,
            'mobile' => $attributes['mobile'] ?? null,
            'status' => $attributes['status'] ?? 'active',
            'notes' => $attributes['notes'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Simpan roles awal jika diberikan
        if (!empty($attributes['roles']) && is_array($attributes['roles'])) {
            foreach ($attributes['roles'] as $role) {
                if (is_string($role)) {
                    $this->addRole($contactId, $role);
                } elseif (is_array($role) && !empty($role['role'])) {
                    $this->addRole(
                        $contactId,
                        (string) $role['role'],
                        $role['metadata'] ?? [],
                        !empty($role['is_primary'])
                    );
                }
            }
        }

        // Simpan alamat awal jika diberikan
        if (!empty($attributes['addresses']) && is_array($attributes['addresses'])) {
            foreach ($attributes['addresses'] as $address) {
                if (is_array($address)) {
                    $this->addAddress($contactId, $address);
                }
            }
        }

        return $this->find($contactId) ?? throw new RuntimeException("Failed to load created contact.");
    }

    public function update(int $id, array $attributes): Contact
    {
        $tenantId = $this->getTenantId();
        $existing = $this->find($id);
        if (!$existing) {
            throw new RuntimeException("Contact with ID {$id} not found.");
        }

        $fields = [];
        if (isset($attributes['name'])) {
            $fields['name'] = trim((string) $attributes['name']);
        }
        if (isset($attributes['legal_name'])) {
            $fields['legal_name'] = $attributes['legal_name'];
        }
        if (isset($attributes['type'])) {
            $fields['type'] = $attributes['type'];
        }
        if (isset($attributes['email'])) {
            $fields['email'] = $attributes['email'];
        }
        if (isset($attributes['phone'])) {
            $fields['phone'] = $attributes['phone'];
        }
        if (isset($attributes['mobile'])) {
            $fields['mobile'] = $attributes['mobile'];
        }
        if (isset($attributes['status'])) {
            $fields['status'] = $attributes['status'];
        }
        if (isset($attributes['notes'])) {
            $fields['notes'] = $attributes['notes'];
        }

        // Update PII jika ada perubahan
        if (array_key_exists('id_number', $attributes)) {
            $fields['id_number'] = !empty($attributes['id_number']) ? encrypt((string) $attributes['id_number']) : null;
        }
        if (array_key_exists('tax_id', $attributes)) {
            $fields['tax_id'] = !empty($attributes['tax_id']) ? encrypt((string) $attributes['tax_id']) : null;
        }

        if (!empty($fields)) {
            $fields['updated_at'] = date('Y-m-d H:i:s');
            $setParts = [];
            $bindings = [];
            foreach ($fields as $col => $val) {
                $setParts[] = "{$col} = ?";
                $bindings[] = $val;
            }
            $bindings[] = $id;
            $bindings[] = $tenantId;

            $sql = "UPDATE contacts SET " . implode(', ', $setParts) . " WHERE id = ? AND tenant_id = ?";
            $this->db->statement($sql, $bindings);
        }

        return $this->find($id) ?? throw new RuntimeException("Failed to reload updated contact.");
    }

    public function delete(int $id): bool
    {
        $tenantId = $this->getTenantId();
        $now = date('Y-m-d H:i:s');
        $affected = $this->db->statement(
            "UPDATE contacts SET deleted_at = ?, status = 'archived' WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
            [$now, $id, $tenantId]
        );

        return $affected > 0;
    }

    public function find(int $id): ?Contact
    {
        $tenantId = $this->getTenantId();
        $row = $this->db->selectOne(
            "SELECT * FROM contacts WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
            [$id, $tenantId]
        );

        if (!$row) {
            return null;
        }

        return $this->hydrateContact($row);
    }

    public function findByCode(string $code): ?Contact
    {
        $tenantId = $this->getTenantId();
        $row = $this->db->selectOne(
            "SELECT * FROM contacts WHERE code = ? AND tenant_id = ? AND deleted_at IS NULL",
            [$code, $tenantId]
        );

        if (!$row) {
            return null;
        }

        return $this->hydrateContact($row);
    }

    public function getByRole(string $role, array $filters = []): array
    {
        $tenantId = $this->getTenantId();
        $sql = "SELECT c.* FROM contacts c
                INNER JOIN contact_roles cr ON cr.contact_id = c.id
                WHERE c.tenant_id = ? AND cr.role = ? AND c.deleted_at IS NULL";
        $bindings = [$tenantId, strtolower(trim($role))];

        if (!empty($filters['status'])) {
            $sql .= " AND c.status = ?";
            $bindings[] = $filters['status'];
        }

        if (!empty($filters['type'])) {
            $sql .= " AND c.type = ?";
            $bindings[] = $filters['type'];
        }

        $sql .= " ORDER BY c.name ASC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int) $filters['limit'];
        }

        $rows = $this->db->selectAll($sql, $bindings);
        return array_map([$this, 'hydrateContact'], $rows);
    }

    public function addRole(int $contactId, string $role, array $metadata = [], bool $isPrimary = false): void
    {
        $roleClean = strtolower(trim($role));
        if ($roleClean === '') {
            return;
        }

        // Cek apakah sudah terdaftar
        $existing = $this->db->selectOne(
            "SELECT id FROM contact_roles WHERE contact_id = ? AND role = ?",
            [$contactId, $roleClean]
        );

        if ($existing) {
            if ($isPrimary) {
                $this->db->statement("UPDATE contact_roles SET is_primary = 0 WHERE contact_id = ?", [$contactId]);
                $this->db->statement("UPDATE contact_roles SET is_primary = 1 WHERE id = ?", [$existing['id']]);
            }
            return;
        }

        if ($isPrimary) {
            $this->db->statement("UPDATE contact_roles SET is_primary = 0 WHERE contact_id = ?", [$contactId]);
        }

        $this->db->insert('contact_roles', [
            'contact_id' => $contactId,
            'role' => $roleClean,
            'is_primary' => $isPrimary ? 1 : 0,
            'metadata' => !empty($metadata) ? json_encode($metadata) : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function removeRole(int $contactId, string $role): void
    {
        $this->db->statement(
            "DELETE FROM contact_roles WHERE contact_id = ? AND role = ?",
            [$contactId, strtolower(trim($role))]
        );
    }

    public function hasRole(int $contactId, string $role): bool
    {
        $res = $this->db->selectOne(
            "SELECT COUNT(id) as total FROM contact_roles WHERE contact_id = ? AND role = ?",
            [$contactId, strtolower(trim($role))]
        );

        return ((int) ($res['total'] ?? 0)) > 0;
    }

    public function search(string $query, int $limit = 20): array
    {
        $tenantId = $this->getTenantId();
        $q = '%' . trim($query) . '%';

        $rows = $this->db->selectAll(
            "SELECT * FROM contacts 
             WHERE tenant_id = ? AND deleted_at IS NULL 
               AND (name LIKE ? OR code LIKE ? OR email LIKE ? OR phone LIKE ? OR mobile LIKE ?)
             ORDER BY name ASC LIMIT ?",
            [$tenantId, $q, $q, $q, $q, $q, $limit]
        );

        return array_map([$this, 'hydrateContact'], $rows);
    }

    public function addAddress(int $contactId, array $addressData): int
    {
        $isDefault = !empty($addressData['is_default']);
        if ($isDefault) {
            $this->db->statement("UPDATE contact_addresses SET is_default = 0 WHERE contact_id = ?", [$contactId]);
        }

        $insertId = $this->db->insert('contact_addresses', [
            'contact_id' => $contactId,
            'type' => $addressData['type'] ?? 'primary',
            'address_line1' => $addressData['address_line1'] ?? '',
            'address_line2' => $addressData['address_line2'] ?? null,
            'city' => $addressData['city'] ?? null,
            'province' => $addressData['province'] ?? null,
            'postal_code' => $addressData['postal_code'] ?? null,
            'country' => $addressData['country'] ?? 'ID',
            'latitude' => isset($addressData['latitude']) ? (float) $addressData['latitude'] : null,
            'longitude' => isset($addressData['longitude']) ? (float) $addressData['longitude'] : null,
            'is_default' => $isDefault ? 1 : 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $insertId;
    }

    public function linkAffiliation(
        int $personId,
        int $organizationId,
        string $relationType = 'employee_of',
        ?string $jobTitle = null
    ): int {
        $insertId = $this->db->insert('contact_relations', [
            'parent_contact_id' => $organizationId,
            'child_contact_id' => $personId,
            'relation_type' => $relationType,
            'job_title' => $jobTitle,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $insertId;
    }

    private function hydrateContact(array $row): Contact
    {
        $contactId = (int) $row['id'];

        // Ambil roles
        $roleRows = $this->db->selectAll("SELECT role FROM contact_roles WHERE contact_id = ? ORDER BY is_primary DESC, id ASC", [$contactId]);
        $row['roles'] = array_column($roleRows, 'role');

        // Ambil addresses
        $addrRows = $this->db->selectAll("SELECT * FROM contact_addresses WHERE contact_id = ? ORDER BY is_default DESC, id ASC", [$contactId]);
        $row['addresses'] = $addrRows;

        // Ambil relations
        $relRows = $this->db->selectAll("SELECT * FROM contact_relations WHERE child_contact_id = ? OR parent_contact_id = ?", [$contactId, $contactId]);
        $row['relations'] = $relRows;

        return Contact::fromArray($row);
    }
}
