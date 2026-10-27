<?php

declare(strict_types=1);

namespace Core\Middleware;

use Core\Http\Request;
use Core\Http\Response;

class SanitizeInput
{
    /**
     * Membersihkan seluruh input request dari karakter null-bytes berbahaya dan memangkas whitespace
     *
     * @param callable(Request): Response $next
     */
    public function handle(Request $request, callable $next): Response
    {
        $cleanedQuery = $this->cleanArray($request->query());
        $cleanedBody = $this->cleanArray($request->all());

        $request->setQueryParams($cleanedQuery);
        $request->setBodyParams($cleanedBody);

        return $next($request);
    }

    /**
     * Bersihkan array secara rekursif
     *
     * @param array<string|int, mixed> $data
     * @return array<string|int, mixed>
     */
    public function cleanArray(array $data): array
    {
        $cleaned = [];
        foreach ($data as $key => $value) {
            $cleanKey = is_string($key) ? str_replace("\0", '', trim($key)) : $key;
            $cleaned[$cleanKey] = $this->cleanValue($value);
        }
        return $cleaned;
    }

    /**
     * Bersihkan nilai individual
     */
    public function cleanValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->cleanArray($value);
        }

        if (is_string($value)) {
            // 1. Bersihkan null-bytes (\0) yang sering dipakai membobol fungsi file/string C
            $value = str_replace("\0", '', $value);

            // 2. Trim whitespace di awal dan akhir
            return trim($value);
        }

        return $value;
    }
}
