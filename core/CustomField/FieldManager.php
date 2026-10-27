<?php

declare(strict_types=1);

namespace Core\CustomField;

use Core\Database\Connection;
use Core\Database\TenantContext;

class FieldManager
{
    public function __construct(
        protected Connection $db,
        protected ?TenantContext $tenantContext = null
    ) {
    }

    /**
     * Daftarkan atau perbarui definisi field kustom untuk entity type tertentu.
     */
    public function define(
        string $entityType,
        string $fieldName,
        string $label,
        string $type = 'text',
        array $options = [],
        bool $isRequired = false,
        int|string|null $tenantId = null
    ): array {
        $allowedTypes = ['text', 'number', 'date', 'select', 'boolean'];
        if (!in_array($type, $allowedTypes, true)) {
            $type = 'text';
        }

        $tenantId = $tenantId ?? ($this->tenantContext ? $this->tenantContext->getTenantId() : 1) ?? 1;
        $fieldName = preg_replace('/[^a-z0-9_]/', '', strtolower($fieldName));
        $optionsJson = !empty($options) ? json_encode($options, JSON_UNESCAPED_UNICODE) : null;
        $requiredInt = $isRequired ? 1 : 0;

        // Upsert definition
        $existing = $this->db->selectOne(
            "SELECT id FROM custom_field_definitions WHERE tenant_id = ? AND entity_type = ? AND field_name = ?",
            [$tenantId, $entityType, $fieldName]
        );

        if ($existing) {
            $this->db->statement(
                "UPDATE custom_field_definitions 
                 SET label = ?, type = ?, options = ?, is_required = ? 
                 WHERE id = ?",
                [$label, $type, $optionsJson, $requiredInt, $existing['id']]
            );
            $fieldId = (int) $existing['id'];
        } else {
            $this->db->statement(
                "INSERT INTO custom_field_definitions (tenant_id, entity_type, field_name, label, type, options, is_required)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$tenantId, $entityType, $fieldName, $label, $type, $optionsJson, $requiredInt]
            );
            $fieldId = (int) $this->db->getPdo()->lastInsertId();
        }

        return [
            'id' => $fieldId,
            'tenant_id' => $tenantId,
            'entity_type' => $entityType,
            'field_name' => $fieldName,
            'label' => $label,
            'type' => $type,
            'options' => $options,
            'is_required' => $isRequired,
        ];
    }

    /**
     * Ambil seluruh definisi field kustom untuk suatu tipe entitas.
     */
    public function getFields(string $entityType, int|string|null $tenantId = null): array
    {
        $tenantId = $tenantId ?? ($this->tenantContext ? $this->tenantContext->getTenantId() : 1) ?? 1;

        $rows = $this->db->selectAll(
            "SELECT * FROM custom_field_definitions WHERE tenant_id = ? AND entity_type = ? ORDER BY id ASC",
            [$tenantId, $entityType]
        );

        return array_map(function ($row) {
            $row['options'] = !empty($row['options']) ? json_decode($row['options'], true) : [];
            $row['is_required'] = (bool) $row['is_required'];
            return $row;
        }, $rows);
    }

    /**
     * Validasi array nilai input terhadap definisi custom fields yang terdaftar.
     * Mengembalikan array error jika tidak valid, atau array kosong jika lolos.
     */
    public function validate(string $entityType, array $inputs, int|string|null $tenantId = null): array
    {
        $fields = $this->getFields($entityType, $tenantId);
        $errors = [];

        foreach ($fields as $field) {
            $name = $field['field_name'];
            $val = $inputs[$name] ?? null;

            // 1. Cek Required
            if ($field['is_required'] && ($val === null || $val === '')) {
                $errors[$name][] = "Kolom [{$field['label']}] wajib diisi.";
                continue;
            }

            if ($val === null || $val === '') {
                continue;
            }

            // 2. Cek Type Validation
            switch ($field['type']) {
                case 'number':
                    if (!is_numeric($val)) {
                        $errors[$name][] = "Kolom [{$field['label']}] harus berupa angka valid.";
                    }
                    break;
                case 'date':
                    if (strtotime((string) $val) === false) {
                        $errors[$name][] = "Kolom [{$field['label']}] harus berupa tanggal valid.";
                    }
                    break;
                case 'select':
                    $allowedOptions = (array) ($field['options'] ?? []);
                    if (!empty($allowedOptions) && !in_array((string) $val, $allowedOptions, true)) {
                        $errors[$name][] = "Pilihan pada [{$field['label']}] tidak valid.";
                    }
                    break;
                case 'boolean':
                    $boolFilter = filter_var($val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($boolFilter === null) {
                        $errors[$name][] = "Kolom [{$field['label']}] harus berupa boolean.";
                    }
                    break;
            }
        }

        return $errors;
    }

    /**
     * Simpan nilai-nilai custom fields untuk suatu entitas spesifik.
     */
    public function saveValues(
        string $entityType,
        int|string $entityId,
        array $values,
        int|string|null $tenantId = null
    ): bool {
        $tenantId = $tenantId ?? ($this->tenantContext ? $this->tenantContext->getTenantId() : 1) ?? 1;
        $fields = $this->getFields($entityType, $tenantId);
        $fieldMap = [];
        foreach ($fields as $f) {
            $fieldMap[$f['field_name']] = $f['id'];
        }

        foreach ($values as $fieldName => $value) {
            if (!isset($fieldMap[$fieldName])) {
                continue;
            }
            $fieldId = $fieldMap[$fieldName];
            $strVal = is_bool($value) ? ($value ? '1' : '0') : (is_array($value) ? json_encode($value) : (string) $value);

            // Upsert into custom_field_values
            $existing = $this->db->selectOne(
                "SELECT id FROM custom_field_values WHERE tenant_id = ? AND entity_type = ? AND entity_id = ? AND field_id = ?",
                [$tenantId, $entityType, (string) $entityId, $fieldId]
            );

            if ($existing) {
                $this->db->statement(
                    "UPDATE custom_field_values SET value = ?, updated_at = datetime('now') WHERE id = ?",
                    [$strVal, $existing['id']]
                );
            } else {
                $this->db->statement(
                    "INSERT INTO custom_field_values (tenant_id, entity_type, entity_id, field_id, value, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, datetime('now'), datetime('now'))",
                    [$tenantId, $entityType, (string) $entityId, $fieldId, $strVal]
                );
            }
        }

        return true;
    }

    /**
     * Ambil seluruh nilai custom fields untuk entitas spesifik dalam format key-value.
     */
    public function getValues(string $entityType, int|string $entityId, int|string|null $tenantId = null): array
    {
        $tenantId = $tenantId ?? ($this->tenantContext ? $this->tenantContext->getTenantId() : 1) ?? 1;

        $rows = $this->db->selectAll(
            "SELECT d.field_name, d.type, v.value 
             FROM custom_field_values v
             JOIN custom_field_definitions d ON v.field_id = d.id
             WHERE v.tenant_id = ? AND v.entity_type = ? AND v.entity_id = ?",
            [$tenantId, $entityType, (string) $entityId]
        );

        $result = [];
        foreach ($rows as $row) {
            $val = $row['value'];
            if ($row['type'] === 'number' && is_numeric($val)) {
                $val = str_contains((string) $val, '.') ? (float) $val : (int) $val;
            } elseif ($row['type'] === 'boolean') {
                $val = ($val === '1' || $val === 'true');
            }
            $result[$row['field_name']] = $val;
        }

        return $result;
    }

    /**
     * Hapus definisi custom field dan seluruh data nilainya.
     */
    public function deleteField(string $entityType, string $fieldName, int|string|null $tenantId = null): bool
    {
        $tenantId = $tenantId ?? ($this->tenantContext ? $this->tenantContext->getTenantId() : 1) ?? 1;

        $field = $this->db->selectOne(
            "SELECT id FROM custom_field_definitions WHERE tenant_id = ? AND entity_type = ? AND field_name = ?",
            [$tenantId, $entityType, $fieldName]
        );

        if (!$field) {
            return false;
        }

        $this->db->statement("DELETE FROM custom_field_values WHERE field_id = ?", [$field['id']]);
        $this->db->statement("DELETE FROM custom_field_definitions WHERE id = ?", [$field['id']]);

        return true;
    }
}
