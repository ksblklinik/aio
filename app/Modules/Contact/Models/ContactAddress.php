<?php

declare(strict_types=1);

namespace App\Modules\Contact\Models;

class ContactAddress
{
    public function __construct(
        public ?int $id = null,
        public int $contactId = 0,
        public string $type = 'primary',
        public string $addressLine1 = '',
        public ?string $addressLine2 = null,
        public ?string $city = null,
        public ?string $province = null,
        public ?string $postalCode = null,
        public string $country = 'ID',
        public ?float $latitude = null,
        public ?float $longitude = null,
        public bool $isDefault = false,
        public ?string $createdAt = null
    ) {
    }

    public static function fromArray(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            contactId: (int) ($row['contact_id'] ?? 0),
            type: (string) ($row['type'] ?? 'primary'),
            addressLine1: (string) ($row['address_line1'] ?? ''),
            addressLine2: isset($row['address_line2']) ? (string) $row['address_line2'] : null,
            city: isset($row['city']) ? (string) $row['city'] : null,
            province: isset($row['province']) ? (string) $row['province'] : null,
            postalCode: isset($row['postal_code']) ? (string) $row['postal_code'] : null,
            country: (string) ($row['country'] ?? 'ID'),
            latitude: isset($row['latitude']) ? (float) $row['latitude'] : null,
            longitude: isset($row['longitude']) ? (float) $row['longitude'] : null,
            isDefault: !empty($row['is_default']),
            createdAt: isset($row['created_at']) ? (string) $row['created_at'] : null
        );
    }
}
