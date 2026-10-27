<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Foundation\Application;
use Core\Http\Request;
use Core\Http\Response;
use Core\Middleware\SanitizeInput;
use Core\Middleware\SecurityHeaders;
use Core\Security\Encrypter;
use Core\Security\Totp;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CoreAdvancedSecurityTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Application(dirname(__DIR__, 2));
    }

    // ========================================================
    // 1. SECURITY HEADERS MIDDLEWARE TESTS
    // ========================================================

    public function testSecurityHeadersInjectedIntoResponse(): void
    {
        $middleware = new SecurityHeaders();
        $req = new Request('GET', '/dashboard');

        $res = $middleware->handle($req, function (Request $r) {
            return Response::html('<h1>Dashboard</h1>');
        });

        $headers = $res->getHeaders();

        $this->assertEquals('SAMEORIGIN', $headers['X-Frame-Options'] ?? null);
        $this->assertEquals('nosniff', $headers['X-Content-Type-Options'] ?? null);
        $this->assertEquals('strict-origin-when-cross-origin', $headers['Referrer-Policy'] ?? null);
        $this->assertEquals('0', $headers['X-XSS-Protection'] ?? null);
        $this->assertStringContainsString('default-src', $headers['Content-Security-Policy'] ?? '');
        $this->assertStringContainsString('camera=(self)', $headers['Permissions-Policy'] ?? '');
    }

    public function testSecurityHeadersHstsOnSecureRequest(): void
    {
        $middleware = new SecurityHeaders();
        // Request dengan HTTPS
        $req = new Request(
            method: 'GET',
            uri: '/secure',
            headers: ['x-forwarded-proto' => 'https']
        );

        $res = $middleware->handle($req, function (Request $r) {
            return Response::json(['secure' => true]);
        });

        $this->assertArrayHasKey('Strict-Transport-Security', $res->getHeaders());
        $this->assertStringContainsString('max-age=31536000', $res->getHeaders()['Strict-Transport-Security']);
    }

    // ========================================================
    // 2. AES-256-GCM ENCRYPTER TESTS
    // ========================================================

    public function testEncrypterEncryptAndDecrypt(): void
    {
        $key = 'secret-32-chars-key-for-test!!';
        $encrypter = new Encrypter($key);

        $originalText = 'Data Rahasia Yayasan / NIK 3578012345670001';
        $encrypted = $encrypter->encrypt($originalText);

        $this->assertNotEmpty($encrypted);
        $this->assertNotEquals($originalText, $encrypted);

        $decrypted = $encrypter->decrypt($encrypted);
        $this->assertEquals($originalText, $decrypted);
    }

    public function testEncrypterHandlesComplexDataTypes(): void
    {
        $encrypter = new Encrypter('test-key-32-chars-long-12345678');
        $payload = [
            'bank_account' => '1234567890',
            'bank_name' => 'BCA',
            'balance' => 75000000.50,
            'is_verified' => true,
        ];

        $encrypted = $encrypter->encrypt($payload);
        $decrypted = $encrypter->decrypt($encrypted);

        $this->assertIsArray($decrypted);
        $this->assertEquals($payload, $decrypted);
    }

    public function testEncrypterDetectsTampering(): void
    {
        $this->expectException(RuntimeException::class);

        $encrypter = new Encrypter('test-key-32-chars-long-12345678');
        $encrypted = $encrypter->encrypt('sensitif');

        // Manipulasi string payload (tampering)
        $raw = json_decode((string) base64_decode($encrypted), true);
        $raw['value'] = base64_encode('hacked_ciphertext');
        $tampered = base64_encode((string) json_encode($raw));

        $encrypter->decrypt($tampered);
    }

    // ========================================================
    // 3. NATIVE TWO-FACTOR AUTH (TOTP RFC 6238) TESTS
    // ========================================================

    public function testTotpSecretGenerationAndCodeVerification(): void
    {
        $totp = new Totp();
        $secret = $totp->generateSecret(16);

        $this->assertEquals(16, strlen($secret));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);

        $currentTime = 1700000000;
        $code = $totp->generateCode($secret, $currentTime);

        $this->assertEquals(6, strlen($code));
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        // Verifikasi kode yang benar pada waktu yang sama
        $this->assertTrue($totp->verifyCode($secret, $code, discrepancy: 1, timestamp: $currentTime));

        // Verifikasi toleransi drift waktu (+- 1 step = 30 detik)
        $this->assertTrue($totp->verifyCode($secret, $code, discrepancy: 1, timestamp: $currentTime + 25));

        // Kode salah harus ditolak
        $this->assertFalse($totp->verifyCode($secret, '000000', discrepancy: 1, timestamp: $currentTime));
    }

    public function testTotpProvisioningUriAndQrCode(): void
    {
        $totp = new Totp();
        $secret = 'JBSWY3DPEHPK3PXP';
        $uri = $totp->getProvisioningUri('budi@example.com', $secret, 'aio SuperAdmin');

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        $this->assertStringContainsString('issuer=aio+SuperAdmin', $uri);

        $qrSvg = $totp->getQrCodeSvg('budi@example.com', $secret, 'aio SuperAdmin', 180);
        $this->assertStringContainsString('<svg', $qrSvg);
        $this->assertStringContainsString('width="180" height="180"', $qrSvg);
        $this->assertStringContainsString('viewBox="0 0 ', $qrSvg);
    }

    // ========================================================
    // 4. SANITIZE INPUT MIDDLEWARE TESTS
    // ========================================================

    public function testSanitizeInputStripsNullBytesAndTrimsWhitespace(): void
    {
        $middleware = new SanitizeInput();
        $req = new Request(
            method: 'POST',
            uri: '/submit',
            queryParams: ['filter' => "  aktif\0  "],
            bodyParams: [
                'name' => "  Ahmad Subarjo\0  ",
                'email' => ' ahmad@test.com ',
                'nested' => [
                    'payload' => "  test\0value  ",
                ],
            ]
        );

        $middleware->handle($req, function (Request $r) {
            $this->assertEquals('aktif', $r->query('filter'));
            $this->assertEquals('Ahmad Subarjo', $r->input('name'));
            $this->assertEquals('ahmad@test.com', $r->input('email'));
            $this->assertEquals('testvalue', $r->input('nested')['payload']);
            return Response::json(['ok' => true]);
        });
    }

    // ========================================================
    // 5. GLOBAL HELPERS TESTS
    // ========================================================

    public function testGlobalSecurityHelpers(): void
    {
        $text = 'Kerahasiaan Platform aio';
        $cipher = encrypt($text);
        $this->assertNotEquals($text, $cipher);
        $this->assertEquals($text, decrypt($cipher));

        $this->assertInstanceOf(Totp::class, totp());
    }
}
