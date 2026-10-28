<?php

declare(strict_types=1);

namespace Core\Lock;

use RuntimeException;

class LockTimeoutException extends RuntimeException
{
    public function __construct(string $key, int $timeoutMs)
    {
        parent::__construct("Gagal mengakuisisi lock untuk resource [{$key}] dalam batas waktu {$timeoutMs}ms.");
    }
}
