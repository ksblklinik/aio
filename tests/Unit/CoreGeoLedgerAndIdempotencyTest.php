<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Database\Connection;
use Core\Database\TenantContext;
use Core\Foundation\Application;
use Core\Geo\Geo;
use Core\Http\Request;
use Core\Http\Response;
use Core\Ledger\LedgerManager;
use Core\Middleware\IdempotentRequest;
use Core\Router;
use PHPUnit\Framework\TestCase;

class CoreGeoLedgerAndIdempotencyTest extends TestCase
{
    private Application $app;
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Application(dirname(__DIR__, 2));
        $this->db = new Connection('sqlite::memory:');

        TenantContext::getInstance()->setTenantId('1', ['name' => 'Canvassing Depo']);

        // Inisialisasi skema ledger test di in-memory SQLite
        $this->db->getPdo()->exec("
            CREATE TABLE IF NOT EXISTS ledgers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                account_type TEXT NOT NULL,
                account_id TEXT NOT NULL,
                currency TEXT NOT NULL DEFAULT 'IDR',
                balance REAL NOT NULL DEFAULT 0.0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(tenant_id, account_type, account_id)
            );

            CREATE TABLE IF NOT EXISTS ledger_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                ledger_id INTEGER NOT NULL,
                direction TEXT NOT NULL,
                amount REAL NOT NULL,
                balance_after REAL NOT NULL,
                reference_type TEXT,
                reference_id TEXT,
                description TEXT,
                metadata TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
        ");
    }

    public function test_geo_distance_and_radius(): void
    {
        // Koordinat Monas Jakarta: -6.175392, 106.827153
        $monasLat = -6.175392;
        $monasLng = 106.827153;

        // Koordinat Gedung Sate Bandung: -6.902476, 107.618797
        $bandungLat = -6.902476;
        $bandungLng = 107.618797;

        // Jarak Jakarta - Bandung garis lurus sekitar 118 - 122 km
        $distanceKm = Geo::distance($monasLat, $monasLng, $bandungLat, $bandungLng, 'km');
        $this->assertGreaterThan(115.0, $distanceKm);
        $this->assertLessThan(125.0, $distanceKm);

        // Uji titik dekat dalam radius 50 meter
        // Geser sedikit ~20 meter: -6.175392 -> -6.175500
        $nearLat = -6.175500;
        $nearLng = 106.827153;

        $distMeters = Geo::distance($monasLat, $monasLng, $nearLat, $nearLng, 'm');
        $this->assertLessThan(25.0, $distMeters);

        // InRadius test
        $this->assertTrue(Geo::inRadius($nearLat, $nearLng, $monasLat, $monasLng, 50.0));
        $this->assertFalse(Geo::inRadius($bandungLat, $bandungLng, $monasLat, $monasLng, 500.0));

        // Global helpers
        $this->assertEquals($distanceKm, geo_distance($monasLat, $monasLng, $bandungLat, $bandungLng));
        $this->assertTrue(geo_in_radius($nearLat, $nearLng, $monasLat, $monasLng, 50.0));
    }

    public function test_geo_bearing(): void
    {
        // Dari Jakarta ke arah Timur (+lng) -> mendekati 90 derajat
        $bearingEast = Geo::bearing(0.0, 100.0, 0.0, 101.0);
        $this->assertEquals(90.0, $bearingEast);

        // Dari Khatulistiwa ke Utara (+lat) -> mendekati 0 / 360 derajat
        $bearingNorth = Geo::bearing(0.0, 100.0, 1.0, 100.0);
        $this->assertEquals(0.0, $bearingNorth);
    }

    public function test_ledger_atomic_balance_mutations(): void
    {
        $ledger = new LedgerManager($this->db, TenantContext::getInstance());

        // 1. Initial balance 0
        $this->assertSame(0.0, $ledger->getBalance('driver_wallet', 'DRV-001'));

        // 2. Topup credit Rp 100.000
        $res1 = $ledger->record(
            accountType: 'driver_wallet',
            accountId: 'DRV-001',
            amount: 100000.0,
            direction: 'credit',
            referenceType: 'topup',
            referenceId: 'TOP-101',
            description: 'Topup saldo driver via Alfamart'
        );

        $this->assertSame('credit', $res1['direction']);
        $this->assertSame(100000.0, $res1['amount']);
        $this->assertSame(100000.0, $res1['balance_after']);
        $this->assertSame(100000.0, $ledger->getBalance('driver_wallet', 'DRV-001'));

        // 3. Potong komisi order debit Rp 20.000
        $res2 = $ledger->record(
            accountType: 'driver_wallet',
            accountId: 'DRV-001',
            amount: 20000.0,
            direction: 'debit',
            referenceType: 'order',
            referenceId: 'ORD-555',
            description: 'Komisi platform order #ORD-555'
        );

        $this->assertSame('debit', $res2['direction']);
        $this->assertSame(80000.0, $res2['balance_after']);
        $this->assertSame(80000.0, $ledger->getBalance('driver_wallet', 'DRV-001'));

        // 4. Overdraft protection: Debit Rp 90.000 saat saldo hanya Rp 80.000
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient balance');

        $ledger->record(
            accountType: 'driver_wallet',
            accountId: 'DRV-001',
            amount: 90000.0,
            direction: 'debit',
            allowNegative: false
        );
    }

    public function test_ledger_statement_history(): void
    {
        $ledger = new LedgerManager($this->db, TenantContext::getInstance());

        $ledger->record('customer_receivable', 'CUST-09', 500000.0, 'credit', 'invoice', 'INV-001', 'Faktur Penjualan Toko');
        $ledger->record('customer_receivable', 'CUST-09', 200000.0, 'debit', 'payment', 'PAY-001', 'Pembayaran Cicilan Toko');

        $statement = $ledger->getStatement('customer_receivable', 'CUST-09');
        $this->assertCount(2, $statement);
        $this->assertSame('debit', $statement[0]['direction']);
        $this->assertSame('credit', $statement[1]['direction']);

        // Helper global ledger()
        $this->assertInstanceOf(LedgerManager::class, ledger());
    }

    public function test_idempotent_request_middleware_prevents_duplicate_orders(): void
    {
        $storageDir = dirname(__DIR__, 2) . '/storage/framework/test_idempotency';
        $middleware = new IdempotentRequest($storageDir, TenantContext::getInstance());

        $executionCount = 0;
        $handler = function (Request $req) use (&$executionCount) {
            $executionCount++;
            return Response::json([
                'order_id' => 'ORD-' . $executionCount,
                'status' => 'created',
            ], 201);
        };

        $idempotencyKey = 'test-key-' . uniqid();

        // Request 1: Dieksekusi normal
        $req1 = new Request('POST', '/api/orders', [
            'X-Idempotency-Key' => $idempotencyKey,
            'Content-Type' => 'application/json',
        ]);
        $res1 = $middleware->handle($req1, $handler);

        $this->assertSame(201, $res1->getStatusCode());
        $this->assertSame(1, $executionCount);
        $data1 = json_decode($res1->getBody(), true);
        $this->assertSame('ORD-1', $data1['order_id']);

        // Request 2 (Retry dengan key yang sama): Tidak mengeksekusi handler lagi, mengembalikan respon cache
        $req2 = new Request('POST', '/api/orders', [
            'X-Idempotency-Key' => $idempotencyKey,
            'Content-Type' => 'application/json',
        ]);
        $res2 = $middleware->handle($req2, $handler);

        $this->assertSame(201, $res2->getStatusCode());
        $this->assertSame('HIT-IDEMPOTENT', $res2->getHeader('X-Cache-Lookup'));
        $this->assertSame(1, $executionCount); // Tetap 1, tidak dieksekusi dobel!
        $data2 = json_decode($res2->getBody(), true);
        $this->assertSame('ORD-1', $data2['order_id']);

        // Cleanup test directory
        @array_map('unlink', glob($storageDir . '/*'));
        @rmdir($storageDir);
    }
}
