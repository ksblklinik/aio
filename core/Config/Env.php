<?php

declare(strict_types=1);

namespace Core\Config;

class Env
{
    /**
     * @var array<string, mixed>
     */
    private static array $repository = [];

    /**
     * Muat file .env dan parse ke environment
     */
    public static function loadFile(string $filePath, bool $overwrite = false): bool
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return false;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return false;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Abaikan baris komentar atau baris kosong
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Parse baris KEY=VALUE
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $val = trim($parts[1]);

            // Tangani komentar inline jika bukan di dalam quotes
            if (!str_starts_with($val, '"') && !str_starts_with($val, "'")) {
                $commentPos = strpos($val, '#');
                if ($commentPos !== false) {
                    $val = trim(substr($val, 0, $commentPos));
                }
            }

            $parsedValue = self::castValue($val);

            self::set($key, $parsedValue, $overwrite);
        }

        return true;
    }

    /**
     * Ambil nilai environment
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$repository)) {
            return self::$repository[$key];
        }

        if (array_key_exists($key, $_ENV)) {
            return self::castValue((string) $_ENV[$key]);
        }

        $serverVal = getenv($key);
        if ($serverVal !== false) {
            return self::castValue($serverVal);
        }

        return $default;
    }

    /**
     * Set nilai environment
     */
    public static function set(string $key, mixed $value, bool $overwrite = true): void
    {
        if (!$overwrite && (array_key_exists($key, self::$repository) || array_key_exists($key, $_ENV) || getenv($key) !== false)) {
            return;
        }

        self::$repository[$key] = $value;
        $_ENV[$key] = $value;
        
        $stringValue = is_bool($value) ? ($value ? 'true' : 'false') : (is_null($value) ? '' : (string) $value);
        putenv("{$key}={$stringValue}");
    }

    /**
     * Cast string mentah ke tipe data PHP yang sesuai
     */
    private static function castValue(string $val): mixed
    {
        // Quotes handling (Single Quotes)
        if (str_starts_with($val, "'") && str_ends_with($val, "'")) {
            return substr($val, 1, -1);
        }

        // Quotes handling (Double Quotes)
        if (str_starts_with($val, '"') && str_ends_with($val, '"')) {
            $inner = substr($val, 1, -1);
            return str_replace(['\\"', '\\n', '\\t'], ['"', "\n", "\t"], $inner);
        }

        $lower = strtolower($val);
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }
        if ($lower === 'null') {
            return null;
        }
        if ($val === '') {
            return '';
        }
        if (is_numeric($val)) {
            return str_contains($val, '.') ? (float) $val : (int) $val;
        }

        return $val;
    }
}
