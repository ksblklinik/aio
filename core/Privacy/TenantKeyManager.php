<?php

declare(strict_types=1);

namespace Core\Privacy;

use Core\Database\Connection;
use Core\Privacy\Exceptions\TenantKeyShreddedException;

class TenantKeyManager
{
    private Connection $db;
    private string $masterKey;
    private array $keyCache = [];

    public function __construct(Connection $db, ?string $masterKey = null)
    {
        $this->db = $db;
        $this->masterKey = $masterKey ?? (string) (config('app.key') ?: env('APP_KEY', 'aio-universal-secret-key-32chars!!'));
    }

    /**
     * Get or create a cryptographic key salt for a tenant.
     *
     * @throws TenantKeyShreddedException
     */
    public function getOrCreateKeySalt(int $tenantId): string
    {
        $record = $this->db->selectOne(
            "SELECT key_salt, is_shredded FROM tenant_encryption_keys WHERE tenant_id = :tenant_id",
            ['tenant_id' => $tenantId]
        );

        if ($record !== null) {
            if ((int) $record['is_shredded'] === 1) {
                throw new TenantKeyShreddedException($tenantId);
            }
            return (string) $record['key_salt'];
        }

        $salt = bin2hex(random_bytes(32));
        $now = date('c');

        $this->db->statement(
            "INSERT INTO tenant_encryption_keys (tenant_id, key_salt, algorithm, is_shredded, created_at, updated_at) 
             VALUES (:tenant_id, :key_salt, 'aes-256-cbc', 0, :created_at, :updated_at)",
            [
                'tenant_id' => $tenantId,
                'key_salt' => $salt,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        return $salt;
    }

    /**
     * Derive a 256-bit encryption key unique to this tenant.
     *
     * @throws TenantKeyShreddedException
     */
    public function deriveKey(int $tenantId): string
    {
        if (isset($this->keyCache[$tenantId])) {
            return $this->keyCache[$tenantId];
        }

        $salt = $this->getOrCreateKeySalt($tenantId);
        $derived = hash_pbkdf2('sha256', $this->masterKey, (string) $tenantId . $salt, 10000, 32, true);

        $this->keyCache[$tenantId] = $derived;
        return $derived;
    }

    /**
     * Encrypt a plaintext string using the tenant's isolated cryptographic key.
     *
     * @throws TenantKeyShreddedException
     */
    public function encrypt(int $tenantId, string $plaintext): string
    {
        $key = $this->deriveKey($tenantId);
        $iv = random_bytes(16);

        $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new \RuntimeException("Encryption failed for tenant [{$tenantId}].");
        }

        $mac = hash_hmac('sha256', $iv . $ciphertext, $key, true);

        $envelope = [
            'v' => 1,
            'tid' => $tenantId,
            'iv' => base64_encode($iv),
            'data' => base64_encode($ciphertext),
            'mac' => base64_encode($mac),
        ];

        return base64_encode((string) json_encode($envelope));
    }

    /**
     * Decrypt a ciphertext payload using the tenant's isolated cryptographic key.
     * Returns null if key has been shredded or payload cannot be decrypted.
     */
    public function decrypt(int $tenantId, string $payload): ?string
    {
        try {
            $key = $this->deriveKey($tenantId);
        } catch (TenantKeyShreddedException $e) {
            return null;
        }

        $raw = base64_decode($payload, true);
        if ($raw === false) {
            return null;
        }

        $envelope = json_decode($raw, true);
        if (!is_array($envelope) || !isset($envelope['iv'], $envelope['data'], $envelope['mac'])) {
            return null;
        }

        $iv = base64_decode((string) $envelope['iv'], true);
        $data = base64_decode((string) $envelope['data'], true);
        $mac = base64_decode((string) $envelope['mac'], true);

        if ($iv === false || $data === false || $mac === false || strlen($iv) !== 16) {
            return null;
        }

        $calculatedMac = hash_hmac('sha256', $iv . $data, $key, true);
        if (!hash_equals($mac, $calculatedMac)) {
            return null;
        }

        $plaintext = openssl_decrypt($data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return $plaintext !== false ? $plaintext : null;
    }

    /**
     * Check if a tenant's cryptographic key has been shredded.
     */
    public function isKeyShredded(int $tenantId): bool
    {
        $record = $this->db->selectOne(
            "SELECT is_shredded FROM tenant_encryption_keys WHERE tenant_id = :tenant_id",
            ['tenant_id' => $tenantId]
        );

        return $record !== null && (int) $record['is_shredded'] === 1;
    }

    /**
     * Check if a key record exists for the tenant.
     */
    public function hasKey(int $tenantId): bool
    {
        $record = $this->db->selectOne(
            "SELECT id FROM tenant_encryption_keys WHERE tenant_id = :tenant_id",
            ['tenant_id' => $tenantId]
        );

        return $record !== null;
    }

    /**
     * Instantly crypto-shred the tenant's cryptographic key.
     * Securely overwrites the key salt with random noise and marks it shredded.
     */
    public function shredKey(int $tenantId): bool
    {
        // Remove from memory cache immediately
        unset($this->keyCache[$tenantId]);

        $randomNoise = bin2hex(random_bytes(32));
        $now = date('c');

        // Overwrite existing record or insert shredded stub
        $existing = $this->db->selectOne(
            "SELECT id FROM tenant_encryption_keys WHERE tenant_id = :tenant_id",
            ['tenant_id' => $tenantId]
        );

        if ($existing !== null) {
            $this->db->statement(
                "UPDATE tenant_encryption_keys 
                 SET key_salt = :noise, is_shredded = 1, shredded_at = :shredded_at, updated_at = :updated_at 
                 WHERE tenant_id = :tenant_id",
                [
                    'noise' => $randomNoise,
                    'shredded_at' => $now,
                    'updated_at' => $now,
                    'tenant_id' => $tenantId,
                ]
            );
        } else {
            $this->db->statement(
                "INSERT INTO tenant_encryption_keys (tenant_id, key_salt, algorithm, is_shredded, shredded_at, created_at, updated_at)
                 VALUES (:tenant_id, :noise, 'aes-256-cbc', 1, :shredded_at, :created_at, :updated_at)",
                [
                    'tenant_id' => $tenantId,
                    'noise' => $randomNoise,
                    'shredded_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        return true;
    }
}
