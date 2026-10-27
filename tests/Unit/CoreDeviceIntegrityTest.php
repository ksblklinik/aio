<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Audit\AuditLogger;
use Core\Database\Connection;
use Core\Security\IntegrityGuard;
use PHPUnit\Framework\TestCase;

class CoreDeviceIntegrityTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = new Connection('sqlite::memory:');
        $this->db->statement("
            CREATE TABLE IF NOT EXISTS audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NULL,
                user_id INTEGER NULL,
                user_name TEXT NULL,
                action TEXT NOT NULL,
                description TEXT NOT NULL,
                entity_type TEXT NULL,
                entity_id TEXT NULL,
                properties TEXT NULL,
                ip_address TEXT NULL,
                user_agent TEXT NULL,
                prev_hash TEXT NULL,
                current_hash TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
        ");
    }

    // ========================================================
    // 1. GEOLOCATION & IMPOSSIBLE TRAVEL TESTS
    // ========================================================

    public function testDistanceAndSpeedCalculation(): void
    {
        $guard = new IntegrityGuard('test-secret');

        // Monas Jakarta (-6.1754, 106.8272) ke Gedung Sate Bandung (-6.9025, 107.6186) ~115-125 km
        $distance = $guard->calculateDistanceKm(-6.1754, 106.8272, -6.9025, 107.6186);
        $this->assertGreaterThan(110.0, $distance);
        $this->assertLessThan(130.0, $distance);

        // Jika ditempuh dalam 2 jam (7200 detik) -> speed ~60 km/jam
        $now = time();
        $speed = $guard->calculateSpeedKmh(-6.1754, 106.8272, $now, -6.9025, 107.6186, $now + 7200);
        $this->assertGreaterThan(55.0, $speed);
        $this->assertLessThan(65.0, $speed);
    }

    public function testImpossibleTravelDetection(): void
    {
        $guard = new IntegrityGuard('test-secret');

        $t1 = 1700000000;
        // Jakarta (-6.1754, 106.8272)
        $lat1 = -6.1754;
        $lon1 = 106.8272;

        // Tokyo (35.6762, 139.6503) ~5800 km
        $lat2 = 35.6762;
        $lon2 = 139.6503;

        // Kasus 1: Selisih waktu 10 menit (600 detik) -> Kecepatan ~34.800 km/jam! (Impossible Travel)
        $t2 = $t1 + 600;
        $this->assertTrue($guard->isImpossibleTravel($lat1, $lon1, $t1, $lat2, $lon2, $t2));

        // Kasus 2: Selisih waktu 8 jam penerbangan (28.800 detik) -> Kecepatan ~725 km/jam (Possible Travel)
        $t3 = $t1 + 28800;
        $this->assertFalse($guard->isImpossibleTravel($lat1, $lon1, $t1, $lat2, $lon2, $t3, maxSpeedKmh: 1000.0));

        // Kasus 3: Titik sama pada detik yang sama -> Bukan impossible travel
        $this->assertFalse($guard->isImpossibleTravel($lat1, $lon1, $t1, $lat1, $lon1, $t1));
    }

    public function testLocationValidationAndMockDetection(): void
    {
        $guard = new IntegrityGuard('test-secret');

        // Valid telemetry
        $resValid = $guard->validateLocation([
            'latitude' => -6.2088,
            'longitude' => 106.8456,
            'accuracy' => 12.5,
            'is_mock' => false,
        ]);
        $this->assertTrue($resValid['valid']);
        $this->assertEmpty($resValid['flags']);

        // Mock Location terdeteksi
        $resMock = $guard->validateLocation([
            'latitude' => -6.2088,
            'longitude' => 106.8456,
            'accuracy' => 0.0,
            'is_mock' => true,
        ]);
        $this->assertFalse($resMock['valid']);
        $this->assertContains('mock_location_detected', $resMock['flags']);
        $this->assertContains('zero_or_negative_accuracy', $resMock['flags']);

        // Out-of-bounds coordinates
        $resInvalid = $guard->validateLocation([
            'latitude' => 95.0,
            'longitude' => 200.0,
        ]);
        $this->assertFalse($resInvalid['valid']);
        $this->assertContains('invalid_coordinates_range', $resInvalid['flags']);
    }

    // ========================================================
    // 2. TIME DRIFT & CLOCK TAMPERING
    // ========================================================

    public function testTimeDriftClockTamperingDetection(): void
    {
        $guard = new IntegrityGuard('test-secret');
        $serverTime = 1700000000;

        // Drift 30 detik (dalam batas toleransi default 120s) -> False
        $this->assertFalse($guard->detectTimeDrift($serverTime - 30, toleranceSeconds: 120, serverTimestamp: $serverTime));

        // Jam HP dimundurkan 1 jam (3600 detik) -> True (Tampered)
        $this->assertTrue($guard->detectTimeDrift($serverTime - 3600, toleranceSeconds: 120, serverTimestamp: $serverTime));

        // Jam HP dimajukan 10 menit (600 detik) -> True (Tampered)
        $this->assertTrue($guard->detectTimeDrift($serverTime + 600, toleranceSeconds: 120, serverTimestamp: $serverTime));
    }

    // ========================================================
    // 3. DEVICE FINGERPRINTING & BINDING
    // ========================================================

    public function testDeviceFingerprintGenerationAndBinding(): void
    {
        $guard = new IntegrityGuard('test-secret');

        $deviceA = [
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'screen_resolution' => '1920x1080',
            'timezone' => 'Asia/Jakarta',
            'canvas_hash' => 'a1b2c3d4e5',
        ];

        $hashA = $guard->generateDeviceHash($deviceA);
        $this->assertNotEmpty($hashA);
        $this->assertSame(64, strlen($hashA)); // SHA-256 length

        // Konsistensi pembangkitan hash
        $hashA2 = $guard->generateDeviceHash($deviceA);
        $this->assertSame($hashA, $hashA2);

        // Binding verification
        $this->assertTrue($guard->verifyDeviceBinding($hashA, $hashA2));
        $this->assertFalse($guard->verifyDeviceBinding($hashA, 'invalid_or_different_hash'));
    }

    // ========================================================
    // 4. DISPOSABLE EMAIL FILTER
    // ========================================================

    public function testDisposableEmailDetection(): void
    {
        $guard = new IntegrityGuard('test-secret');

        // Email sah
        $this->assertFalse($guard->isDisposableEmail('budi@gmail.com'));
        $this->assertFalse($guard->isDisposableEmail('admin@perusahaan.co.id'));

        // Burner / Disposable email
        $this->assertTrue($guard->isDisposableEmail('spammer@mailinator.com'));
        $this->assertTrue($guard->isDisposableEmail('fraudster@10minutemail.com'));
        $this->assertTrue($guard->isDisposableEmail('anon@tempmail.com'));
        $this->assertTrue($guard->isDisposableEmail('hacker@yopmail.com'));
    }

    // ========================================================
    // 5. TAMPER-EVIDENT HASH-CHAINED AUDIT LOG
    // ========================================================

    public function testAuditLogHashChainingAndTamperVerification(): void
    {
        $_ENV['APP_KEY'] = 'test-audit-key-secret-123';
        $logger = new AuditLogger($this->db);

        // Catat 3 log berurutan
        $id1 = $logger->log('auth.login', 'User budi logged in', entityType: 'user', entityId: '1');
        $id2 = $logger->log('project.created', 'Created project Alpha', entityType: 'project', entityId: '10');
        $id3 = $logger->log('task.created', 'Created task Deployment', entityType: 'task', entityId: '100');

        $rows = $this->db->selectAll("SELECT id, prev_hash, current_hash FROM audit_logs ORDER BY id ASC");
        $this->assertCount(3, $rows);

        // Verifikasi pointer rantai
        $this->assertSame('0000000000000000000000000000000000000000000000000000000000000000', $rows[0]['prev_hash']);
        $this->assertSame($rows[0]['current_hash'], $rows[1]['prev_hash']);
        $this->assertSame($rows[1]['current_hash'], $rows[2]['prev_hash']);

        // Verifikasi integritas rantai secara utuh
        $result = $logger->verifyChain();
        $this->assertTrue($result['valid']);
        $this->assertNull($result['broken_at_id']);
        $this->assertSame(3, $result['verified_count']);
    }

    public function testAuditLogDetectsTamperedRecord(): void
    {
        $_ENV['APP_KEY'] = 'test-audit-key-secret-123';
        $logger = new AuditLogger($this->db);

        $id1 = $logger->log('auth.login', 'User login');
        $id2 = $logger->log('invoice.paid', 'Invoice #100 paid Rp 50.000.000');
        $id3 = $logger->log('auth.logout', 'User logout');

        // Oknum nakal memanipulasi baris id2 langsung di database (mengubah action atau nominal)
        $this->db->statement("UPDATE audit_logs SET action = 'invoice.cancelled' WHERE id = ?", [$id2]);

        $result = $logger->verifyChain();
        $this->assertFalse($result['valid']);
        $this->assertSame($id2, $result['broken_at_id']);
        $this->assertSame(1, $result['verified_count']); // Hanya baris 1 yang valid sebelum putus
    }

    public function testAuditLogDetectsDeletedRecord(): void
    {
        $_ENV['APP_KEY'] = 'test-audit-key-secret-123';
        $logger = new AuditLogger($this->db);

        $id1 = $logger->log('auth.login', 'User login');
        $id2 = $logger->log('invoice.paid', 'Invoice #100 paid');
        $id3 = $logger->log('auth.logout', 'User logout');

        // Oknum menghapus baris id2 untuk menghilangkan jejak
        $this->db->statement("DELETE FROM audit_logs WHERE id = ?", [$id2]);

        $result = $logger->verifyChain();
        $this->assertFalse($result['valid']);
        $this->assertSame($id3, $result['broken_at_id']); // Baris id3 menunjuk prev_hash yang sudah hilang
    }
}
