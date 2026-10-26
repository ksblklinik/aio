<?php

declare(strict_types=1);

namespace Core\Validation;

use Core\Database\TenantContext;
use PDO;

class Validator
{
    /**
     * @var array<string, mixed>
     */
    private array $data;

    /**
     * @var array<string, string|array<int, string>>
     */
    private array $rules;

    private ?PDO $pdo;

    /**
     * @var array<string, string>
     */
    private array $errors = [];

    /**
     * @var array<string, mixed>
     */
    private array $validatedData = [];

    private bool $hasRun = false;

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|array<int, string>> $rules
     */
    public function __construct(array $data, array $rules, ?PDO $pdo = null)
    {
        $this->data = $data;
        $this->rules = $rules;
        $this->pdo = $pdo;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|array<int, string>> $rules
     */
    public static function make(array $data, array $rules, ?PDO $pdo = null): self
    {
        return new self($data, $rules, $pdo);
    }

    public function passes(): bool
    {
        if (!$this->hasRun) {
            $this->executeValidation();
        }
        return empty($this->errors);
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        if (!$this->hasRun) {
            $this->executeValidation();
        }
        return $this->errors;
    }

    public function first(?string $field = null): ?string
    {
        $errors = $this->errors();
        if ($field !== null) {
            return $errors[$field] ?? null;
        }
        return reset($errors) ?: null;
    }

    /**
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function validated(): array
    {
        if ($this->fails()) {
            throw new ValidationException($this->errors);
        }
        return $this->validatedData;
    }

    private function executeValidation(): void
    {
        $this->hasRun = true;
        $this->errors = [];
        $this->validatedData = [];

        foreach ($this->rules as $field => $fieldRules) {
            $rulesList = is_array($fieldRules) ? $fieldRules : explode('|', (string) $fieldRules);
            $value = $this->data[$field] ?? null;

            $isRequired = in_array('required', $rulesList, true);
            $isEmpty = $value === null || $value === '' || $value === [];

            if ($isRequired && $isEmpty) {
                $this->addError($field, $this->formatFieldName($field) . ' wajib diisi.');
                continue;
            }

            // Jika field opsional dan kosong, lewati validasi lainnya tapi tetap simpan null/default
            if ($isEmpty) {
                $this->validatedData[$field] = $value;
                continue;
            }

            foreach ($rulesList as $rule) {
                if ($rule === 'required') {
                    continue;
                }

                $ruleName = $rule;
                $parameter = null;

                if (str_contains($rule, ':')) {
                    [$ruleName, $parameter] = explode(':', $rule, 2);
                }

                $this->validateRule($field, $value, $ruleName, $parameter);

                if (isset($this->errors[$field])) {
                    break; // Berhenti di kesalahan pertama untuk field ini
                }
            }

            if (!isset($this->errors[$field])) {
                $this->validatedData[$field] = $value;
            }
        }
    }

    private function validateRule(string $field, mixed $value, string $rule, ?string $param): void
    {
        $label = $this->formatFieldName($field);

        switch ($rule) {
            case 'string':
                if (!is_string($value)) {
                    $this->addError($field, "{$label} harus berupa teks string.");
                }
                break;

            case 'numeric':
                if (!is_numeric($value)) {
                    $this->addError($field, "{$label} harus berupa angka numerik.");
                }
                break;

            case 'integer':
                if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                    $this->addError($field, "{$label} harus berupa bilangan bulat.");
                }
                break;

            case 'email':
                if (!is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    $this->addError($field, "Format {$label} tidak valid.");
                }
                break;

            case 'min':
                $min = (int) $param;
                if (is_numeric($value) && !is_string($value)) {
                    if ($value < $min) {
                        $this->addError($field, "{$label} minimal harus bernilai {$min}.");
                    }
                } elseif (is_array($value)) {
                    if (count($value) < $min) {
                        $this->addError($field, "{$label} minimal harus memiliki {$min} item.");
                    }
                } else {
                    if (mb_strlen((string) $value) < $min) {
                        $this->addError($field, "{$label} minimal harus {$min} karakter.");
                    }
                }
                break;

            case 'max':
                $max = (int) $param;
                if (is_numeric($value) && !is_string($value)) {
                    if ($value > $max) {
                        $this->addError($field, "{$label} maksimal bernilai {$max}.");
                    }
                } elseif (is_array($value)) {
                    if (count($value) > $max) {
                        $this->addError($field, "{$label} maksimal memiliki {$max} item.");
                    }
                } else {
                    if (mb_strlen((string) $value) > $max) {
                        $this->addError($field, "{$label} tidak boleh lebih dari {$max} karakter.");
                    }
                }
                break;

            case 'in':
                $allowed = array_map('trim', explode(',', (string) $param));
                if (!in_array((string) $value, $allowed, true)) {
                    $this->addError($field, "Pilihan {$label} tidak valid.");
                }
                break;

            case 'confirmed':
                $confirmationField = $field . '_confirmation';
                $confirmationValue = $this->data[$confirmationField] ?? null;
                if ($value !== $confirmationValue) {
                    $this->addError($field, "Konfirmasi {$label} tidak cocok.");
                }
                break;

            case 'regex':
                if ($param && @preg_match($param, (string) $value) !== 1) {
                    $this->addError($field, "Format {$label} tidak sesuai pola yang ditentukan.");
                }
                break;

            case 'file':
                $isValidFile = ($value instanceof \Core\Http\UploadedFile && $value->isValid())
                    || (is_array($value) && isset($value['tmp_name']) && ($value['error'] ?? null) === UPLOAD_ERR_OK);
                if (!$isValidFile) {
                    $this->addError($field, "{$label} harus berupa berkas file yang valid.");
                }
                break;

            case 'mimes':
                $ext = '';
                if ($value instanceof \Core\Http\UploadedFile) {
                    $ext = strtolower($value->getClientOriginalExtension());
                } elseif (is_array($value) && isset($value['name'])) {
                    $ext = strtolower(pathinfo((string) $value['name'], PATHINFO_EXTENSION));
                } elseif (is_string($value)) {
                    $ext = strtolower(pathinfo($value, PATHINFO_EXTENSION));
                }
                $allowedMimes = array_map('trim', explode(',', strtolower((string) $param)));
                if (!in_array($ext, $allowedMimes, true)) {
                    $this->addError($field, "Berkas {$label} harus memiliki ekstensi: {$param}.");
                }
                break;

            case 'max_kb':
                $maxKb = (int) $param;
                $sizeInBytes = 0;
                if ($value instanceof \Core\Http\UploadedFile) {
                    $sizeInBytes = $value->getSize();
                } elseif (is_array($value) && isset($value['size'])) {
                    $sizeInBytes = (int) $value['size'];
                }
                if ($sizeInBytes > ($maxKb * 1024)) {
                    $this->addError($field, "Ukuran berkas {$label} tidak boleh lebih dari {$maxKb} KB.");
                }
                break;

            case 'unique':
                $this->validateUnique($field, $value, (string) $param);
                break;
        }
    }

    /**
     * Format: unique:table,column[,ignore_id[,id_column]]
     */
    private function validateUnique(string $field, mixed $value, string $param): void
    {
        if ($this->pdo === null) {
            return;
        }

        $parts = explode(',', $param);
        $table = trim($parts[0] ?? '');
        $column = trim($parts[1] ?? $field);
        $ignoreId = isset($parts[2]) ? trim($parts[2]) : null;
        $idColumn = isset($parts[3]) ? trim($parts[3]) : 'id';

        if ($table === '' || $column === '') {
            return;
        }

        $sql = "SELECT COUNT(*) FROM {$table} WHERE {$column} = ?";
        $bindings = [$value];

        if ($ignoreId !== null && $ignoreId !== '') {
            $sql .= " AND {$idColumn} != ?";
            $bindings[] = $ignoreId;
        }

        // Jika tenant terisolasi dan tabel memiliki kolom tenant_id, scope secara otomatis
        $tenantId = TenantContext::getInstance()->getTenantId();
        if ($tenantId !== null && $this->tableHasColumn($table, 'tenant_id')) {
            $sql .= " AND tenant_id = ?";
            $bindings[] = $tenantId;
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);
            $count = (int) $stmt->fetchColumn();

            if ($count > 0) {
                $label = $this->formatFieldName($field);
                $this->addError($field, "{$label} sudah terdaftar, silakan gunakan yang lain.");
            }
        } catch (\Throwable $e) {
            // Silently ignore or fallback
        }
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        if ($this->pdo === null) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare("PRAGMA table_info({$table})");
            $stmt->execute();
            $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $col) {
                if (($col['name'] ?? '') === $column) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }

    private function addError(string $field, string $message): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = $message;
        }
    }

    private function formatFieldName(string $field): string
    {
        $customLabels = [
            'name' => 'Nama',
            'email' => 'Email',
            'password' => 'Kata Sandi',
            'password_confirmation' => 'Konfirmasi Kata Sandi',
            'company_name' => 'Nama Organisasi / Workspace',
            'category' => 'Kategori',
            'description' => 'Deskripsi',
            'title' => 'Judul',
        ];

        return $customLabels[$field] ?? ucwords(str_replace(['_', '-'], ' ', $field));
    }
}
