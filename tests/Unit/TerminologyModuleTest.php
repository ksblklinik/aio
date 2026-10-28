<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Terminology\Contracts\TerminologyRegistryInterface;
use App\Modules\Terminology\Services\TerminologyService;
use Core\Container;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TerminologyModuleTest extends TestCase
{
    private Connection $db;
    private Migrator $migrator;
    private TenantContext $tenantContext;
    private TerminologyService $service;

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
        $this->db->statement("INSERT INTO tenants (id, name, slug) VALUES (2, 'Eksportir Kopi Nusantara', 'kopi-nusantara')");

        $this->tenantContext->setTenantId('1');

        $this->service = new TerminologyService($this->db, $this->tenantContext);
        $container->instance(TerminologyRegistryInterface::class, $this->service);
        $container->instance(TerminologyService::class, $this->service);
    }

    protected function tearDown(): void
    {
        $this->tenantContext->clear();
        parent::tearDown();
    }

    public function testCreateAndGetCodeSystem(): void
    {
        $cs = $this->service->createCodeSystem([
            'code' => 'ICD-10',
            'name' => 'International Classification of Diseases 10th Revision',
            'uri' => 'http://hl7.org/fhir/sid/icd-10',
            'version' => '2019',
            'publisher' => 'WHO',
            'description' => 'Standard diagnostic classification for epidemiology and health management',
            'is_system' => 1,
        ]);

        $this->assertNotNull($cs);
        $this->assertEquals('ICD-10', $cs['code']);
        $this->assertEquals(1, $cs['is_system']);
        $this->assertNull($cs['tenant_id']);

        $retrieved = $this->service->getCodeSystemByCode('icd-10');
        $this->assertNotNull($retrieved);
        $this->assertEquals($cs['id'], $retrieved['id']);
    }

    public function testDuplicateCodeSystemThrowsException(): void
    {
        $this->service->createCodeSystem([
            'code' => 'LOINC',
            'name' => 'Logical Observation Identifiers Names and Codes',
            'is_system' => 1,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->service->createCodeSystem([
            'code' => 'LOINC',
            'name' => 'Duplicate LOINC',
            'is_system' => 1,
        ]);
    }

    public function testCreateConceptWithSynonymsAndSearchAutocomplete(): void
    {
        $cs = $this->service->createCodeSystem([
            'code' => 'ICD-10',
            'name' => 'WHO ICD-10',
            'is_system' => 1,
        ]);

        // Create concepts
        $c1 = $this->service->createConcept([
            'code_system_id' => $cs['id'],
            'code' => 'A91',
            'display_name' => 'Dengue haemorrhagic fever',
            'description' => 'Severe form of dengue infection',
            'parent_code' => 'A90-A99',
            'synonyms' => ['Demam Berdarah Dengue', 'DBD', 'DHF'],
            'properties' => ['chapter' => 'I', 'block' => 'A90-A99'],
            'is_global' => true,
        ]);

        $c2 = $this->service->createConcept([
            'code_system_id' => $cs['id'],
            'code' => 'J45',
            'display_name' => 'Asthma',
            'synonyms' => ['Asma Bronkial', 'Sesak Nafas Alergi'],
            'is_global' => true,
        ]);

        $c3 = $this->service->createConcept([
            'code_system_id' => $cs['id'],
            'code' => 'A90',
            'display_name' => 'Dengue fever [classical dengue]',
            'synonyms' => ['Demam Dengue', 'Breakbone fever'],
            'is_global' => true,
        ]);

        $this->assertNotNull($c1);
        $this->assertIsArray($c1['synonyms']);
        $this->assertContains('Demam Berdarah Dengue', $c1['synonyms']);
        $this->assertEquals('A90-A99', $c1['parent_code']);

        // 1. Search by exact code
        $resultsCode = $this->service->searchConcepts('A91');
        $this->assertNotEmpty($resultsCode);
        $this->assertEquals('A91', $resultsCode[0]['code']);

        // 2. Search by natural language Indonesian synonym "Demam Berdarah"
        $resultsSynonym = $this->service->searchConcepts('Demam Berdarah');
        $this->assertNotEmpty($resultsSynonym);
        $this->assertEquals('A91', $resultsSynonym[0]['code']);

        // 3. Search by partial term "Dengue" should match A91 and A90
        $resultsDengue = $this->service->searchConcepts('Dengue', 'ICD-10');
        $this->assertCount(2, $resultsDengue);

        // 4. Search by display name "Asthma"
        $resultsAsthma = $this->service->searchConcepts('Asthma');
        $this->assertCount(1, $resultsAsthma);
        $this->assertEquals('J45', $resultsAsthma[0]['code']);
    }

    public function testCrossReferenceMappingAndResolution(): void
    {
        // 1. Setup internal code system & BPJS code system
        $csInternal = $this->service->createCodeSystem([
            'code' => 'INTERNAL-TND',
            'name' => 'Katalog Tindakan Internal Klinik',
            'is_system' => 0,
        ]);

        $csBpjs = $this->service->createCodeSystem([
            'code' => 'BPJS-TND',
            'name' => 'Katalog Tindakan BPJS Kesehatan PCare',
            'is_system' => 1,
        ]);

        // Concept in BPJS
        $this->service->createConcept([
            'code_system_id' => $csBpjs['id'],
            'code' => '0101',
            'display_name' => 'Pemeriksaan Kesehatan Dokter Umum (Konsultasi)',
            'is_global' => true,
        ]);

        // 2. Create mapping: INTERNAL-TND 'KONS-01' -> BPJS-TND '0101'
        $mapping = $this->service->createMapping([
            'source_system_code' => 'INTERNAL-TND',
            'source_code' => 'KONS-01',
            'target_system_code' => 'BPJS-TND',
            'target_code' => '0101',
            'equivalence' => 'equivalent',
            'comments' => 'Konsultasi poli umum reguler',
        ]);

        $this->assertNotNull($mapping);
        $this->assertEquals('KONS-01', $mapping['source_code']);
        $this->assertEquals('0101', $mapping['target_code']);

        // 3. Resolve mapping
        $resolved = $this->service->resolveMapping('INTERNAL-TND', 'KONS-01', 'BPJS-TND');
        $this->assertNotNull($resolved);
        $this->assertEquals('0101', $resolved['target_code']);
        $this->assertEquals('BPJS-TND', $resolved['target_system_code']);
        $this->assertNotNull($resolved['target_concept']);
        $this->assertEquals('Pemeriksaan Kesehatan Dokter Umum (Konsultasi)', $resolved['target_concept']['display_name']);

        // 4. Resolve non-existent mapping returns null
        $unresolved = $this->service->resolveMapping('INTERNAL-TND', 'NON-EXISTENT', 'BPJS-TND');
        $this->assertNull($unresolved);
    }

    public function testBulkImportConcepts(): void
    {
        $cs = $this->service->createCodeSystem([
            'code' => 'HS-CODE',
            'name' => 'Harmonized System Codes Indonesia 2022',
            'is_system' => 1,
        ]);

        $batch = [
            [
                'code' => '0901.11.10',
                'display_name' => 'Kopi Arabika Tidak Digongseng, Tidak Dikafeinasi',
                'description' => 'Biji kopi arabika mentah ekspor',
                'parent_code' => '0901',
                'synonyms' => ['Green Coffee Beans Arabica', 'Kopi Mentah'],
            ],
            [
                'code' => '0901.11.20',
                'display_name' => 'Kopi Robusta Tidak Digongseng, Tidak Dikafeinasi',
                'description' => 'Biji kopi robusta mentah ekspor',
                'parent_code' => '0901',
                'synonyms' => ['Green Coffee Beans Robusta'],
            ],
            [
                'code' => '0901.21.00',
                'display_name' => 'Kopi Digongseng, Tidak Dikafeinasi',
                'description' => 'Roasted Coffee Beans',
                'parent_code' => '0901',
                'synonyms' => ['Kopi Sangrai', 'Roasted Beans'],
            ],
        ];

        $imported = $this->service->bulkImportConcepts($cs['id'], $batch);
        $this->assertEquals(3, $imported);

        $lookup = $this->service->getConceptByCode('HS-CODE', '0901.11.10');
        $this->assertNotNull($lookup);
        $this->assertEquals('Kopi Arabika Tidak Digongseng, Tidak Dikafeinasi', $lookup['display_name']);
        $this->assertContains('Green Coffee Beans Arabica', $lookup['synonyms']);
    }

    public function testTenantIsolationOnCustomCodeSystemsAndMappings(): void
    {
        // Tenant 1 creates custom code system
        $this->tenantContext->setTenantId('1');
        $csTenant1 = $this->service->createCodeSystem([
            'code' => 'CUSTOM-T1',
            'name' => 'Custom Codes Tenant 1',
            'is_system' => 0,
        ]);

        $conceptT1 = $this->service->createConcept([
            'code_system_id' => $csTenant1['id'],
            'code' => 'T1-001',
            'display_name' => 'Private Item Tenant 1',
        ]);

        // Tenant 2 switches context
        $this->tenantContext->setTenantId('2');

        // Tenant 2 should not find Tenant 1's custom code system
        $notFoundCs = $this->service->getCodeSystemByCode('CUSTOM-T1');
        $this->assertNull($notFoundCs);

        // Tenant 2 should not find Tenant 1's concept in search
        $searchTenant2 = $this->service->searchConcepts('Private Item');
        $this->assertEmpty($searchTenant2);
    }

    public function testHelperFunctionTerminology(): void
    {
        $helperService = terminology();
        $this->assertInstanceOf(TerminologyRegistryInterface::class, $helperService);
    }

    public function testApiControllerSearchAndResolveMapping(): void
    {
        $cs1 = $this->service->createCodeSystem([
            'code' => 'ICD-10',
            'name' => 'WHO ICD-10',
            'is_system' => 1,
        ]);

        $this->service->createConcept([
            'code_system_id' => $cs1['id'],
            'code' => 'B01.9',
            'display_name' => 'Varicella without complication',
            'synonyms' => ['Cacar Air', 'Chickenpox'],
            'is_global' => true,
        ]);

        $apiController = new \App\Modules\Terminology\Controllers\ApiTerminologyController($this->service);

        // Test API search
        $request = new \Core\Http\Request(
            method: 'GET',
            uri: '/api/terminology/search',
            queryParams: ['q' => 'Cacar Air', 'system' => 'ICD-10']
        );
        $response = $apiController->search($request);
        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $this->assertEquals('success', $body['status']);
        $this->assertCount(1, $body['data']);
        $this->assertEquals('B01.9', $body['data'][0]['code']);

        // Test API resolve mapping
        $cs2 = $this->service->createCodeSystem([
            'code' => 'INTERNAL-DX',
            'name' => 'Internal Diagnosis',
            'is_system' => 0,
        ]);

        $this->service->createMapping([
            'source_system_code' => 'INTERNAL-DX',
            'source_code' => 'CACAR-01',
            'target_system_code' => 'ICD-10',
            'target_code' => 'B01.9',
            'equivalence' => 'equivalent',
        ]);

        $resolveReq = new \Core\Http\Request(
            method: 'POST',
            uri: '/api/terminology/resolve-mapping',
            bodyParams: [
                'source_system_code' => 'INTERNAL-DX',
                'source_code' => 'CACAR-01',
                'target_system_code' => 'ICD-10',
            ]
        );
        $resolveRes = $apiController->resolveMapping($resolveReq);
        $this->assertEquals(200, $resolveRes->getStatusCode());
        $resolveBody = json_decode($resolveRes->getContent(), true);
        $this->assertEquals('success', $resolveBody['status']);
        $this->assertEquals('B01.9', $resolveBody['data']['target_code']);
        $this->assertEquals('Varicella without complication', $resolveBody['data']['target_concept']['display_name']);
    }

    public function testWebControllerLifecycle(): void
    {
        $session = new \Core\Http\Session();
        $session->startArrayDriver();
        $auth = new \App\Modules\Auth\Services\AuthService($this->db, $session, $this->tenantContext);

        $webController = new \App\Modules\Terminology\Controllers\TerminologyController(
            $auth,
            $this->service,
            $this->db,
            $session
        );

        // Store custom code system
        $storeCsReq = new \Core\Http\Request(
            method: 'POST',
            uri: '/terminology/code-systems',
            bodyParams: [
                'code' => 'LAB-INT',
                'name' => 'Internal Laboratory Codes',
                'description' => 'Tenant custom lab dictionary',
            ]
        );
        $resCs = $webController->storeCodeSystem($storeCsReq);
        $this->assertEquals(302, $resCs->getStatusCode());

        $createdCs = $this->service->getCodeSystemByCode('LAB-INT');
        $this->assertNotNull($createdCs);

        // Store concept via web controller
        $storeConceptReq = new \Core\Http\Request(
            method: 'POST',
            uri: '/terminology/concepts',
            bodyParams: [
                'code_system_id' => $createdCs['id'],
                'code' => 'HB-01',
                'display_name' => 'Hemoglobin Rutin',
                'synonyms' => 'Hb, Hemoglobin Darah, Darah Rutin',
            ]
        );
        $resConcept = $webController->storeConcept($storeConceptReq);
        $this->assertEquals(302, $resConcept->getStatusCode());

        $createdConcept = $this->service->getConceptByCode('LAB-INT', 'HB-01');
        $this->assertNotNull($createdConcept);
        $this->assertContains('Hb', $createdConcept['synonyms']);

        // Test Index JSON response
        $indexReq = new \Core\Http\Request(
            method: 'GET',
            uri: '/terminology',
            queryParams: ['code_system_id' => $createdCs['id']]
        );
        $resIndex = $webController->index($indexReq);
        $this->assertEquals(200, $resIndex->getStatusCode());
        $indexData = json_decode($resIndex->getContent(), true);
        $this->assertArrayHasKey('codeSystems', $indexData);
        $this->assertArrayHasKey('concepts', $indexData);
        $this->assertNotEmpty($indexData['concepts']);
    }
}

