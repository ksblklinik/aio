<?php

declare(strict_types=1);

namespace App\Modules\Contact\Models;

class ContactRole
{
    public function __construct(
        public ?int $id = null,
        public int $contactId = 0,
        public string $role = '',
        public bool $isPrimary = false,
        public ?array $metadata = null,
        public ?string $createdAt = null
    ) {
    }

    public static function fromArray(array $row): self
    {
        $meta = $row['metadata'] ?? null;
        if (is_string($meta)) {
            $meta = json_decode($meta, true) ?: [];
        }

        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            contactId: (int) ($row['contact_id'] ?? 0),
            role: (string) ($row['role'] ?? ''),
            isPrimary: !empty($row['is_primary']),
            metadata: $meta,
            createdAt: isset($row['created_at']) ? (string) $row['created_at'] : null
        );
    }
}
