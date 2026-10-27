<?php

declare(strict_types=1);

namespace App\Modules\Contact\Models;

class ContactRelation
{
    public function __construct(
        public ?int $id = null,
        public int $parentContactId = 0,
        public int $childContactId = 0,
        public string $relationType = 'employee_of',
        public ?string $jobTitle = null,
        public ?string $createdAt = null
    ) {
    }

    public static function fromArray(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            parentContactId: (int) ($row['parent_contact_id'] ?? 0),
            childContactId: (int) ($row['child_contact_id'] ?? 0),
            relationType: (string) ($row['relation_type'] ?? 'employee_of'),
            jobTitle: isset($row['job_title']) ? (string) $row['job_title'] : null,
            createdAt: isset($row['created_at']) ? (string) $row['created_at'] : null
        );
    }
}
