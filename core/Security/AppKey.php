<?php

declare(strict_types=1);

namespace Core\Security;

use Core\Security\Exceptions\InsecureAppKeyException;

class AppKey
{
    private const DEV_FALLBACK_KEY = 'aio-dev-insecure-master-key-32ch!';

    /**
     * Get the authoritative 32-byte application encryption and signing key.
     *
     * @throws InsecureAppKeyException if in production and key is missing or insecure.
     */
    public static function get(): string
    {
        $raw = self::getRaw();

        if ($raw === '') {
            if (self::isProduction()) {
                throw new InsecureAppKeyException(
                    "APP_KEY is missing or empty in production. Run 'php aio key:generate' to generate a secure 256-bit key."
                );
            }
            return self::DEV_FALLBACK_KEY;
        }

        if (str_starts_with($raw, 'base64:')) {
            $decoded = base64_decode(substr($raw, 7), true);
            if ($decoded !== false && strlen($decoded) >= 16) {
                return $decoded;
            }
        }

        if (strlen($raw) < 16 && self::isProduction()) {
            throw new InsecureAppKeyException(
                "APP_KEY is too short (< 16 bytes) for production security. Use at least 32 characters."
            );
        }

        return $raw;
    }

    /**
     * Get the raw APP_KEY string from configuration or environment.
     */
    public static function getRaw(): string
    {
        $key = $_ENV['APP_KEY'] ?? getenv('APP_KEY');
        if (($key === null || $key === '') && function_exists('config')) {
            $key = config('app.key');
        }

        return trim((string) ($key ?? ''));
    }

    /**
     * Check if the application is operating with a known insecure/development key.
     */
    public static function isInsecure(): bool
    {
        $raw = self::getRaw();
        if ($raw === '' || $raw === self::DEV_FALLBACK_KEY) {
            return true;
        }

        // Check if raw key equals any known historical placeholder strings
        $knownPlaceholders = [
            'aio-dev-insecure-master-key-32ch!',
            'aio-universal-secret-key-32chars!!',
            'aio-audit-chain-key',
            'aio-signature-salt-secret-default-key-2026',
            'aio-platform-secret-key-salt',
            'default_secret_key',
        ];

        return in_array($raw, $knownPlaceholders, true);
    }

    /**
     * Generate a cryptographically secure 256-bit base64-encoded application key.
     */
    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    /**
     * Check if currently running in production environment.
     */
    public static function isProduction(): bool
    {
        $env = $_ENV['APP_ENV'] ?? getenv('APP_ENV');
        if (($env === null || $env === '') && function_exists('config')) {
            $env = config('app.env');
        }

        return strtolower((string) ($env ?? 'local')) === 'production';
    }
}
