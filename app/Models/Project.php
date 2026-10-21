<?php

declare(strict_types=1);

namespace App\Models;

class Project
{
    public function __construct(
        public ?int $id = null,
        public string $tenantId = '',
        public string $name = '',
        public string $status = 'Active',
        public int $recordsCount = 0,
        public ?string $updatedAt = null
    ) {
    }
}
