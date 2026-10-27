<?php

declare(strict_types=1);

namespace App\Modules\Contact\Models;

class Contact
{
    /**
     * @param array<string> $roles
     * @param array<array<string, mixed>> $addresses
     * @param array<array<string, mixed>> $relations
     */
    public function __construct(
        public ?int $id = null,
        public int $tenantId = 0,
        public string $type = 'individual', // 'individual' | 'organization'
        public string $code = '',
        public string $name = '',
        public ?string $legalName = null,
        public ?string $idNumber = null,    // Plaintext saat di memori, terenkripsi di DB
        public ?string $taxId = null,       // Plaintext saat di memori, terenkripsi di DB
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $mobile = null,
        public string $status = 'active',   // 'active' | 'inactive' | 'archived'
        public ?string $notes = null,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
        public ?string $deletedAt = null,
        public array $roles = [],
        public array $addresses = [],
        public array $relations = []
    ) {
    }

    public static function fromArray(array $row): self
    {
        // Dekripsi field PII sensitif jika ada
        $idNum = $row['id_number'] ?? null;
        if ($idNum !== null && is_string($idNum) && $idNum !== '') {
            try {
                $idNum = decrypt($idNum);
            } catch (\Throwable) {
                // Biarkan jika format plaintext legacy
            }
        }

        $taxId = $row['tax_id'] ?? null;
        if ($taxId !== null && is_string($taxId) && $taxId !== '') {
            try {
                $taxId = decrypt($taxId);
            } catch (\Throwable) {
                // Biarkan jika format plaintext legacy
            }
        }

        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            tenantId: (int) ($row['tenant_id'] ?? 0),
            type: (string) ($row['type'] ?? 'individual'),
            code: (string) ($row['code'] ?? ''),
            name: (string) ($row['name'] ?? ''),
            legalName: isset($row['legal_name']) ? (string) $row['legal_name'] : null,
            idNumber: $idNum,
            taxId: $taxId,
            email: isset($row['email']) ? (string) $row['email'] : null,
            phone: isset($row['phone']) ? (string) $row['phone'] : null,
            mobile: isset($row['mobile']) ? (string) $row['mobile'] : null,
            status: (string) ($row['status'] ?? 'active'),
            notes: isset($row['notes']) ? (string) $row['notes'] : null,
            createdAt: isset($row['created_at']) ? (string) $row['created_at'] : null,
            updatedAt: isset($row['updated_at']) ? (string) $row['updated_at'] : null,
            deletedAt: isset($row['deleted_at']) ? (string) $row['deleted_at'] : null,
            roles: $row['roles'] ?? [],
            addresses: $row['addresses'] ?? [],
            relations: $row['relations'] ?? []
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'type' => $this->type,
            'code' => $this->code,
            'name' => $this->name,
            'legal_name' => $this->legalName,
            'id_number' => $this->idNumber,
            'tax_id' => $this->taxId,
            'email' => $this->email,
            'phone' => $this->phone,
            'mobile' => $this->mobile,
            'status' => $this->status,
            'notes' => $this->notes,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'deleted_at' => $this->deletedAt,
            'roles' => $this->roles,
            'addresses' => $this->addresses,
            'relations' => $this->relations,
        ];
    }
}
