<?php

declare(strict_types=1);

namespace Core\Security\Exceptions;

use RuntimeException;

class InsecureAppKeyException extends RuntimeException
{
    public function __construct(string $message = 'APP_KEY is missing, empty, or insecure for production environment.')
    {
        parent::__construct($message);
    }
}
