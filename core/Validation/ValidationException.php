<?php

declare(strict_types=1);

namespace Core\Validation;

use Exception;

class ValidationException extends Exception
{
    /**
     * @var array<string, string>
     */
    private array $errors;

    /**
     * @param array<string, string> $errors
     */
    public function __construct(array $errors, string $message = 'Data yang diberikan tidak valid.')
    {
        parent::__construct($message);
        $this->errors = $errors;
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Mendapatkan pesan error pertama
     */
    public function firstError(): string
    {
        return reset($this->errors) ?: $this->getMessage();
    }
}
