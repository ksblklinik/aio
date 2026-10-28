<?php

declare(strict_types=1);

namespace Core\Privacy\Exceptions;

use RuntimeException;

class TenantKeyShreddedException extends RuntimeException
{
    public function __construct(int $tenantId)
    {
        parent::__construct("Cryptographic key for tenant [{$tenantId}] has been permanently shredded and cannot be accessed.");
    }
}
