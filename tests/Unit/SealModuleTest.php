<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Seal\Contracts\DocumentSealRegistryInterface;
use App\Modules\Seal\Services\DocumentSealService;
use Core\Container;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SealModuleTest extends TestCase
{
    private Connection $db;
    private Migrator $migrator;
    private TenantContext $tenantContext;
    private DocumentSealService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new Connection('sqlite::memory:');
        $this->migrator = new Migrator($this->db);
        $this->migrator->runMigrationsFrom(__DIR__ . '/../../database/migrations');

        $this->tenantContext = TenantContext::getInstance();
        $this->tenantContext->clear();

        $container = Container::getInstance();
        $container->instance(Connection::class, $this->db);
        $container->instance(TenantContext::class, $this->tenantContext);

        // Seed 2 Tenants
        $this->db->statement("INSERT INTO tenants (id, name, slug) VALUES (1, 'Klinik Medika Sejahtera', 'medika-sejahtera')");
        $this->db->statement("INSERT INTO tenants (id, name, slug) VALUES (2, 'Poliklinik Prima Husada', 'prima-husada')");

        $this->tenantContext->setTenantId('1');

        $this->service = new DocumentSealService($this->db, $this->tenantContext);
        $container->instance(DocumentSealRegistryInterface::class, $this->service);
        $container->instance(DocumentSealService::class, $this->service);
    }

    protected function tearDown(): void
    {
        $this->tenantContext->clear();
        parent::tearDown();
    }

    public function testSealDocumentCreationAndPublicVerification(): void
    {
        $payload = [
            'document_number' => 'SKD/2026/10/0042',
            'diagnosis' => 'A91 - Dengue haemorrhagic fever',
            'rest_days' => 3,
            'start_date' => '2026-10-28',
            'end_date' => '2026-10-31',
        ];

        $seal = $this->service->sealDocument([
            'document_type' => 'sick_leave',
            'document_number' => 'SKD/2026/10/0042',
            'title' => 'Surat Keterangan Sakit Pasien',
            'issuer_name' => 'Klinik Medika Sejahtera',
            'signer_name' => 'dr. Budi Santoso, Sp.A',
            'signer_role' => 'Dokter Pemeriksa',
            'signer_credential' => 'SIP: 503/SIP/2024',
            'subject_name' => 'Ahmad Fauzi',
            'subject_identifier' => 'NIK: 31710123456789',
            'payload' => $payload,
            'valid_from' => '2026-10-28',
            'valid_until' => '2026-10-31',
        ]);

        $this->assertNotNull($seal);
        $this->assertEquals('SKD/2026/10/0042', $seal['document_number']);
        $this->assertEquals('active', $seal['status']);
        $this->assertNotEmpty($seal['verification_token']);
        $this->assertNotEmpty($seal['canonical_hash']);
        $this->assertNotEmpty($seal['signature']);
        $this->assertStringContainsString('/verify/' . $seal['verification_token'], $seal['verification_url']);

        // Verify public access
        $publicResult = $this->service->verifyPublicDocument($seal['verification_token'], [
            'ip' => '192.168.1.100',
            'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X)',
        ]);

        $this->assertEquals('success', $publicResult['status']);
        $this->assertTrue($publicResult['is_valid']);
        $this->assertNotNull($publicResult['document']);
        $this->assertEquals('dr. Budi Santoso, Sp.A', $publicResult['document']['signer_name']);
        // Verify privacy masking
        $this->assertNotEquals('Ahmad Fauzi', $publicResult['document']['subject_name']);
        $this->assertStringContainsString('*', $publicResult['document']['subject_name']);

        // Verify audit log recorded
        $logs = $this->service->getVerificationLogs((int)$seal['id']);
        $this->assertCount(1, $logs);
        $this->assertEquals('success', $logs[0]['verification_result']);
        $this->assertEquals('192.168.1.100', $logs[0]['ip_address']);
    }

    public function testRevokeSealDocument(): void
    {
        $seal = $this->service->sealDocument([
            'document_type' => 'invoice',
            'document_number' => 'INV/2026/00142',
            'title' => 'Kwitansi Rawat Jalan',
            'signer_name' => 'Siti Rahmawati',
            'subject_name' => 'Dewi Lestari',
            'payload' => ['amount' => 250000],
        ]);

        $revoked = $this->service->revokeSeal($seal['verification_token'], 'Transaksi dibatalkan oleh kasir');
        $this->assertEquals('revoked', $revoked['status']);
        $this->assertEquals('Transaksi dibatalkan oleh kasir', $revoked['revocation_reason']);
        $this->assertNotNull($revoked['revoked_at']);

        // Public verification reflects revoked status
        $verifyRes = $this->service->verifyPublicDocument($seal['verification_token']);
        $this->assertEquals('revoked', $verifyRes['status']);
        $this->assertFalse($verifyRes['is_valid']);
        $this->assertEquals('Transaksi dibatalkan oleh kasir', $verifyRes['document']['revocation_reason']);
    }

    public function testExpiredDocumentVerification(): void
    {
        $seal = $this->service->sealDocument([
            'document_type' => 'certificate',
            'document_number' => 'CERT/2025/001',
            'title' => 'Sertifikat Bebas Narkoba',
            'signer_name' => 'dr. Hendra Wijaya',
            'subject_name' => 'Joko Anwar',
            'valid_from' => '2025-01-01',
            'valid_until' => '2025-06-01', // Past date
            'payload' => ['result' => 'Negative'],
        ]);

        $verifyRes = $this->service->verifyPublicDocument($seal['verification_token']);
        $this->assertEquals('expired', $verifyRes['status']);
        $this->assertFalse($verifyRes['is_valid']);
    }

    public function testVerifyPayloadIntegrity(): void
    {
        $originalPayload = [
            'invoice_number' => 'INV-001',
            'subtotal' => 100000,
            'tax' => 11000,
            'grand_total' => 111000,
        ];

        $seal = $this->service->sealDocument([
            'document_type' => 'invoice',
            'document_number' => 'INV-001',
            'title' => 'Faktur Pelunasan',
            'signer_name' => 'Kasir Utama',
            'subject_name' => 'Rina Gunawan',
            'payload' => $originalPayload,
        ]);

        // Exact match (even if keys in different order)
        $permutedPayload = [
            'grand_total' => 111000,
            'tax' => 11000,
            'subtotal' => 100000,
            'invoice_number' => 'INV-001',
        ];
        $this->assertTrue($this->service->verifyPayloadIntegrity($seal['verification_token'], $permutedPayload));

        // Tampered payload
        $tamperedPayload = [
            'invoice_number' => 'INV-001',
            'subtotal' => 100000,
            'tax' => 0, // Tampered tax
            'grand_total' => 100000,
        ];
        $this->assertFalse($this->service->verifyPayloadIntegrity($seal['verification_token'], $tamperedPayload));
    }

    public function testNonExistentTokenPublicVerification(): void
    {
        $verifyRes = $this->service->verifyPublicDocument('non_existent_fake_token_123');
        $this->assertEquals('not_found', $verifyRes['status']);
        $this->assertFalse($verifyRes['is_valid']);
        $this->assertNull($verifyRes['document']);
    }

    public function testTenantIsolation(): void
    {
        // Tenant 1 seals document
        $this->tenantContext->setTenantId('1');
        $sealT1 = $this->service->sealDocument([
            'document_type' => 'custom',
            'document_number' => 'DOC-T1-01',
            'title' => 'Dokumen Rahasia Tenant 1',
            'signer_name' => 'Manajer T1',
            'subject_name' => 'Klien T1',
            'payload' => ['data' => 'secret-t1'],
        ]);

        // Tenant 2 switches context
        $this->tenantContext->setTenantId('2');

        // Tenant 2 cannot get Tenant 1 seal via back-office
        $notFoundSeal = $this->service->getSeal((int)$sealT1['id']);
        $this->assertNull($notFoundSeal);

        $notFoundByToken = $this->service->getSealByToken($sealT1['verification_token']);
        $this->assertNull($notFoundByToken);

        // Tenant 2 cannot revoke Tenant 1 seal
        $this->expectException(InvalidArgumentException::class);
        $this->service->revokeSeal($sealT1['verification_token'], 'Unauthorized revoke attempt');
    }

    public function testHelperFunctionDocumentSeal(): void
    {
        $helperService = documentSeal();
        $this->assertInstanceOf(DocumentSealRegistryInterface::class, $helperService);
    }

    public function testPublicVerifyController(): void
    {
        $seal = $this->service->sealDocument([
            'document_type' => 'invoice',
            'document_number' => 'INV-TEST-01',
            'title' => 'Kwitansi Publik',
            'signer_name' => 'Bendahara Medika',
            'subject_name' => 'Gunawan Santoso',
            'payload' => ['total' => 500000],
        ]);

        $controller = new \App\Modules\Seal\Controllers\PublicVerifyController($this->service);

        // Valid token
        $req = new \Core\Http\Request(
            method: 'GET',
            uri: '/verify/' . $seal['verification_token'],
            headers: ['user-agent' => 'PublicScannerBot/1.0']
        );
        $res = $controller->verify($req, ['token' => $seal['verification_token']]);
        $this->assertEquals(200, $res->getStatusCode());
        $body = json_decode($res->getContent(), true);
        $this->assertEquals('success', $body['status']);
        $this->assertTrue($body['is_valid']);

        // Invalid token
        $reqInvalid = new \Core\Http\Request(method: 'GET', uri: '/verify/fake-token');
        $resInvalid = $controller->verify($reqInvalid, ['token' => 'fake-token']);
        $this->assertEquals(404, $resInvalid->getStatusCode());
        $bodyInvalid = json_decode($resInvalid->getContent(), true);
        $this->assertEquals('not_found', $bodyInvalid['status']);

        // ApiVerify method
        $apiRes = $controller->apiVerify($req, ['token' => $seal['verification_token']]);
        $this->assertEquals(200, $apiRes->getStatusCode());
    }

    public function testApiSealController(): void
    {
        $controller = new \App\Modules\Seal\Controllers\ApiSealController($this->service);

        // Store via API
        $storeReq = new \Core\Http\Request(
            method: 'POST',
            uri: '/api/seal/documents',
            bodyParams: [
                'document_type' => 'receipt',
                'document_number' => 'REC-API-01',
                'title' => 'Tanda Terima Kasir',
                'signer_name' => 'Petugas Kasir 1',
                'subject_name' => 'Bambang Sudirman',
                'payload' => ['amount' => 75000],
            ]
        );
        $storeRes = $controller->store($storeReq);
        $this->assertEquals(201, $storeRes->getStatusCode());
        $bodyStore = json_decode($storeRes->getContent(), true);
        $token = $bodyStore['data']['verification_token'];

        // Show via API
        $showReq = new \Core\Http\Request(method: 'GET', uri: '/api/seal/documents/' . $token);
        $showRes = $controller->show($showReq, ['token' => $token]);
        $this->assertEquals(200, $showRes->getStatusCode());
        $bodyShow = json_decode($showRes->getContent(), true);
        $this->assertEquals('REC-API-01', $bodyShow['data']['document_number']);

        // Verify Payload via API
        $verifyPayloadReq = new \Core\Http\Request(
            method: 'POST',
            uri: '/api/seal/verify-payload',
            bodyParams: [
                'token' => $token,
                'payload' => ['amount' => 75000],
            ]
        );
        $verifyPayloadRes = $controller->verifyPayload($verifyPayloadReq);
        $this->assertEquals(200, $verifyPayloadRes->getStatusCode());
        $bodyVp = json_decode($verifyPayloadRes->getContent(), true);
        $this->assertTrue($bodyVp['is_integrity_valid']);

        // Revoke via API
        $revokeReq = new \Core\Http\Request(
            method: 'POST',
            uri: '/api/seal/documents/' . $token . '/revoke',
            bodyParams: ['reason' => 'Nota diretur']
        );
        $revokeRes = $controller->revoke($revokeReq, ['token' => $token]);
        $this->assertEquals(200, $revokeRes->getStatusCode());
        $bodyRevoke = json_decode($revokeRes->getContent(), true);
        $this->assertEquals('revoked', $bodyRevoke['data']['status']);
    }

    public function testSealControllerWebLifecycle(): void
    {
        $session = new \Core\Http\Session();
        $session->startArrayDriver();
        $auth = new \App\Modules\Auth\Services\AuthService($this->db, $session, $this->tenantContext);

        $webController = new \App\Modules\Seal\Controllers\SealController(
            $auth,
            $this->service,
            $this->db,
            $session
        );

        // Store via web form
        $storeReq = new \Core\Http\Request(
            method: 'POST',
            uri: '/seal/documents',
            bodyParams: [
                'document_type' => 'sick_leave',
                'document_number' => 'SKD-WEB-01',
                'title' => 'Surat Istirahat',
                'signer_name' => 'dr. Maya',
                'subject_name' => 'Anita Rahayu',
            ]
        );
        $storeRes = $webController->store($storeReq);
        $this->assertEquals(302, $storeRes->getStatusCode());

        $created = $this->service->listSeals(['search' => 'SKD-WEB-01']);
        $this->assertNotEmpty($created);
        $token = $created[0]['verification_token'];

        // Index JSON view
        $indexReq = new \Core\Http\Request(
            method: 'GET',
            uri: '/seal',
            queryParams: ['token' => $token]
        );
        $indexRes = $webController->index($indexReq);
        $this->assertEquals(200, $indexRes->getStatusCode());
        $indexBody = json_decode($indexRes->getContent(), true);
        $this->assertArrayHasKey('seals', $indexBody);
        $this->assertArrayHasKey('selectedSeal', $indexBody);
        $this->assertEquals('SKD-WEB-01', $indexBody['selectedSeal']['document_number']);

        // Revoke via web form
        $revokeReq = new \Core\Http\Request(
            method: 'POST',
            uri: '/seal/documents/' . $token . '/revoke',
            bodyParams: ['reason' => 'Salah input tanggal']
        );
        $revokeRes = $webController->revoke($revokeReq, ['token' => $token]);
        $this->assertEquals(302, $revokeRes->getStatusCode());

        $revokedSeal = $this->service->getSealByToken($token);
        $this->assertEquals('revoked', $revokedSeal['status']);
    }
}

