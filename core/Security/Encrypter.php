<?php

declare(strict_types=1);

namespace Core\Security;

use InvalidArgumentException;
use RuntimeException;

class Encrypter
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;
    private const IV_LENGTH = 12;

    private string $key;

    public function __construct(string $key)
    {
        // Jika key diawali 'base64:', decode terlebih dahulu
        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7));
        }

        // Pastikan panjang kunci tepat 32 byte (256-bit)
        if (strlen($key) !== 32) {
            $key = hash('sha256', $key, true);
        }

        $this->key = $key;
    }

    /**
     * Enkripsi nilai data menggunakan AES-256-GCM authenticated cipher
     */
    public function encrypt(mixed $value, bool $serialize = true): string
    {
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $formatted = $serialize ? serialize($value) : (string) $value;

        $ciphertext = openssl_encrypt(
            $formatted,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new RuntimeException("Encryption operation failed.");
        }

        $payload = json_encode([
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'value' => base64_encode($ciphertext),
            's' => $serialize ? 1 : 0,
        ], JSON_UNESCAPED_SLASHES);

        return base64_encode((string) $payload);
    }

    /**
     * Dekripsi string payload terenkripsi dengan verifikasi integritas Authentication Tag
     */
    public function decrypt(string $payload): mixed
    {
        $rawJson = base64_decode($payload, true);
        if ($rawJson === false) {
            throw new InvalidArgumentException("Invalid base64 payload.");
        }

        $data = json_decode($rawJson, true);
        if (!is_array($data) || !isset($data['iv'], $data['tag'], $data['value'])) {
            throw new InvalidArgumentException("Invalid encryption payload structure.");
        }

        $iv = base64_decode((string) $data['iv'], true);
        $tag = base64_decode((string) $data['tag'], true);
        $ciphertext = base64_decode((string) $data['value'], true);

        if ($iv === false || $tag === false || $ciphertext === false) {
            throw new InvalidArgumentException("Payload components cannot be decoded.");
        }

        $decrypted = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($decrypted === false) {
            throw new RuntimeException("Decryption failed. Authentication tag mismatch or data has been tampered.");
        }

        $isSerialized = ($data['s'] ?? 1) === 1;
        return $isSerialized ? unserialize($decrypted) : $decrypted;
    }
}
