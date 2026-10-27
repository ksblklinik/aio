<?php

declare(strict_types=1);

namespace Core\Security;

use Core\Media\QrCode;
use InvalidArgumentException;

class Totp
{
    private const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Pembangkitan secret key Base32 acak standar RFC 6238
     */
    public function generateSecret(int $length = 16): string
    {
        $secret = '';
        $randomBytes = random_bytes($length);
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::BASE32_CHARS[ord($randomBytes[$i]) % 32];
        }
        return $secret;
    }

    /**
     * Hitung kode 6-digit TOTP untuk secret key pada timestamp tertentu
     */
    public function generateCode(string $secret, ?int $timestamp = null, int $digits = 6, int $period = 30): string
    {
        $time = $timestamp ?? time();
        $counter = (int) floor($time / $period);

        $binarySecret = $this->base32Decode($secret);

        // Pack 64-bit integer big-endian
        $packed = pack('N*', 0) . pack('N*', $counter);

        $hash = hash_hmac('sha1', $packed, $binarySecret, true);

        // Dynamic truncation (RFC 4226)
        $offset = ord($hash[19]) & 0x0f;
        $binary = (
            ((ord($hash[$offset]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        );

        $otp = $binary % (10 ** $digits);
        return str_pad((string) $otp, $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Verifikasi kode TOTP 6-digit dengan toleransi drift waktu (default +- 1 step = 30 detik)
     */
    public function verifyCode(string $secret, string $code, int $discrepancy = 1, ?int $timestamp = null, int $digits = 6, int $period = 30): bool
    {
        $time = $timestamp ?? time();
        $cleanCode = trim($code);

        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $testTime = $time + ($i * $period);
            $expected = $this->generateCode($secret, $testTime, $digits, $period);
            if (hash_equals($expected, $cleanCode)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Dapatkan URI standar otpauth:// untuk scan Google Authenticator / Authy
     */
    public function getProvisioningUri(string $accountName, string $secret, ?string $issuer = null): string
    {
        $cleanIssuer = $issuer ?? 'aio Platform';
        $label = rawurlencode($cleanIssuer) . ':' . rawurlencode($accountName);

        $params = [
            'secret' => trim($secret),
            'issuer' => $cleanIssuer,
            'algorithm' => 'SHA1',
            'digits' => '6',
            'period' => '30',
        ];

        return 'otpauth://totp/' . $label . '?' . http_build_query($params);
    }

    /**
     * Hasilkan gambar QR Code SVG native siap scan untuk aktivasi 2FA pengguna
     */
    public function getQrCodeSvg(string $accountName, string $secret, ?string $issuer = null, int $size = 200): string
    {
        $uri = $this->getProvisioningUri($accountName, $secret, $issuer);
        return QrCode::svg($uri, $size);
    }

    /**
     * Decode string Base32 menjadi byte binary
     */
    private function base32Decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32) ?? '');
        if ($b32 === '') {
            return '';
        }

        $buffer = 0;
        $bitsLeft = 0;
        $output = '';

        $len = strlen($b32);
        for ($i = 0; $i < $len; $i++) {
            $val = strpos(self::BASE32_CHARS, $b32[$i]);
            if ($val === false) {
                continue;
            }

            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xff);
            }
        }

        return $output;
    }
}
