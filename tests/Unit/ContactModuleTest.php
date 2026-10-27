<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Contact\Contracts\ContactRegistryInterface;
use App\Modules\Contact\Services\ContactService;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use Core\Security\Encrypter;
use Core\Sequence\SequenceManager;
use PHPUnit\Framework\TestCase;

class ContactModuleTest extends TestCase
{
    private Connection $db;
    private Migrator $migrator;
    private TenantContext $tenantContext;
    private ContactService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['APP_KEY'] = 'test-secret-key-32chars-for-tests!';

        $this->db = new Connection('sqlite::memory:');
        $this->migrator = new Migrator($this->db);
        $this->migrator->runMigrationsFrom(__DIR__ . '/../../database/migrations');

        $this->tenantContext = TenantContext::getInstance();
        $this->tenantContext->clear();

        $container = \Core\Container::getInstance();
        $container->instance(Connection::class, $this->db);
        $container->instance(TenantContext::class, $this->tenantContext);
        $container->instance(Encrypter::class, new Encrypter($_ENV['APP_KEY']));
        $container->instance(SequenceManager::class, new SequenceManager($this->db));

        // Buat tenant 1 & 2 di DB
        $this->db->statement("INSERT INTO tenants (id, name, slug) VALUES (1, 'Tenant Alpha', 'alpha')");
        $this->db->statement("INSERT INTO tenants (id, name, slug) VALUES (2, 'Tenant Beta', 'beta')");

        $this->service = new ContactService($this->db, $this->tenantContext);
        $container->instance(ContactRegistryInterface::class, $this->service);
    }

    protected function tearDown(): void
    {
        $this->tenantContext->clear();
        parent::tearDown();
    }

    public function testCreateIndividualAndOrganizationContacts(): void
    {
        $this->tenantContext->setTenantId('1');

        // Buat kontak Person / Individual
        $person = $this->service->create([
            'type' => 'individual',
            'name' => 'Budi Santoso',
            'legal_name' => 'Budi Santoso, S.Kom',
            'id_number' => '3201012345670001', // NIK
            'email' => 'budi@example.com',
            'mobile' => '081234567890',
            'roles' => ['customer', 'employee'],
        ]);

        $this->assertNotNull($person->id);
        $this->assertSame('individual', $person->type);
        $this->assertSame('Budi Santoso', $person->name);
        $this->assertStringStartsWith('CNT', $person->code);
        $this->assertSame('3201012345670001', $person->idNumber); // Didekripsi otomatis saat diakses
        $this->assertContains('customer', $person->roles);
        $this->assertContains('employee', $person->roles);

        // Verifikasi bahwa di database tersimpan terenkripsi (tidak plaintext)
        $raw = $this->db->selectOne("SELECT id_number FROM contacts WHERE id = ?", [$person->id]);
        $this->assertNotSame('3201012345670001', $raw['id_number']);
        $this->assertStringContainsString('iv', base64_decode((string) $raw['id_number'])); // Format envelope AES-GCM

        // Buat kontak Organisasi / Perusahaan
        $org = $this->service->create([
            'type' => 'organization',
            'name' => 'PT Maju Bersama',
            'legal_name' => 'Perseroan Terbatas Maju Bersama',
            'tax_id' => '01.234.567.8-901.000', // NPWP
            'email' => 'office@majubersama.com',
            'roles' => ['vendor'],
        ]);

        $this->assertNotNull($org->id);
        $this->assertSame('organization', $org->type);
        $this->assertStringStartsWith('ORG', $org->code);
        $this->assertSame('01.234.567.8-901.000', $org->taxId);
        $this->assertContains('vendor', $org->roles);
    }

    public function testGetByRoleFilter(): void
    {
        $this->tenantContext->setTenantId('1');

        $c1 = $this->service->create(['name' => 'Pelanggan 1', 'roles' => ['customer']]);
        $c2 = $this->service->create(['name' => 'Supplier 1', 'roles' => ['vendor']]);
        $c3 = $this->service->create(['name' => 'Pelanggan & Vendor', 'roles' => ['customer', 'vendor']]);

        $customers = $this->service->getByRole('customer');
        $this->assertCount(2, $customers);
        $customerNames = array_map(fn($c) => $c->name, $customers);
        $this->assertContains('Pelanggan 1', $customerNames);
        $this->assertContains('Pelanggan & Vendor', $customerNames);

        $vendors = $this->service->getByRole('vendor');
        $this->assertCount(2, $vendors);
        $vendorNames = array_map(fn($c) => $c->name, $vendors);
        $this->assertContains('Supplier 1', $vendorNames);
        $this->assertContains('Pelanggan & Vendor', $vendorNames);
    }

    public function testDynamicRoleManagement(): void
    {
        $this->tenantContext->setTenantId('1');

        $contact = $this->service->create(['name' => 'Siti Rahma']);
        $this->assertFalse($this->service->hasRole($contact->id, 'patient'));

        $this->service->addRole($contact->id, 'patient', ['blood_type' => 'O'], isPrimary: true);
        $this->assertTrue($this->service->hasRole($contact->id, 'patient'));

        $reloaded = $this->service->find($contact->id);
        $this->assertContains('patient', $reloaded->roles);

        $this->service->removeRole($contact->id, 'patient');
        $this->assertFalse($this->service->hasRole($contact->id, 'patient'));
    }

    public function testAddressManagementAndGeoCoordination(): void
    {
        $this->tenantContext->setTenantId('1');

        $contact = $this->service->create(['name' => 'Klinik Sehat']);

        $addrId = $this->service->addAddress($contact->id, [
            'type' => 'billing',
            'address_line1' => 'Jl. Sudirman No. 45',
            'city' => 'Jakarta Selatan',
            'province' => 'DKI Jakarta',
            'postal_code' => '12190',
            'latitude' => -6.2146,
            'longitude' => 106.8166,
            'is_default' => true,
        ]);

        $this->assertGreaterThan(0, $addrId);

        $reloaded = $this->service->find($contact->id);
        $this->assertCount(1, $reloaded->addresses);
        $this->assertSame('Jl. Sudirman No. 45', $reloaded->addresses[0]['address_line1']);
        $this->assertSame('Jakarta Selatan', $reloaded->addresses[0]['city']);
        $this->assertEquals(-6.2146, (float) $reloaded->addresses[0]['latitude']);
    }

    public function testAffiliationRelationBetweenPersonAndOrganization(): void
    {
        $this->tenantContext->setTenantId('1');

        $company = $this->service->create(['type' => 'organization', 'name' => 'RS Harapan Kita']);
        $doctor = $this->service->create(['type' => 'individual', 'name' => 'dr. Andika, Sp.PD']);

        $relId = $this->service->linkAffiliation(
            personId: $doctor->id,
            organizationId: $company->id,
            relationType: 'practitioner_at',
            jobTitle: 'Dokter Spesialis Penyakit Dalam'
        );

        $this->assertGreaterThan(0, $relId);

        $reloadedDoctor = $this->service->find($doctor->id);
        $this->assertCount(1, $reloadedDoctor->relations);
        $this->assertSame('practitioner_at', $reloadedDoctor->relations[0]['relation_type']);
        $this->assertSame('Dokter Spesialis Penyakit Dalam', $reloadedDoctor->relations[0]['job_title']);
    }

    public function testSearchAutocomplete(): void
    {
        $this->tenantContext->setTenantId('1');

        $this->service->create(['name' => 'Ahmad Dahlan', 'email' => 'ahmad@dahlan.id']);
        $this->service->create(['name' => 'Bambang Soedirman', 'phone' => '0811223344']);
        $this->service->create(['name' => 'Dahlan Iskan', 'email' => 'diskan@media.com']);

        $results = $this->service->search('Dahlan');
        $this->assertCount(2, $results);

        $resultsPhone = $this->service->search('081122');
        $this->assertCount(1, $resultsPhone);
        $this->assertSame('Bambang Soedirman', $resultsPhone[0]->name);
    }

    public function testMultiTenantIsolation(): void
    {
        // Tenant 1 buat kontak
        $this->tenantContext->setTenantId('1');
        $c1 = $this->service->create(['name' => 'Kontak Rahasia Tenant 1']);

        // Switch ke Tenant 2
        $this->tenantContext->setTenantId('2');
        $this->assertNull($this->service->find($c1->id));
        $this->assertEmpty($this->service->search('Kontak Rahasia'));

        // Tenant 2 buat kontak
        $c2 = $this->service->create(['name' => 'Kontak Tenant 2']);
        $this->assertNotNull($this->service->find($c2->id));

        // Switch kembali ke Tenant 1
        $this->tenantContext->setTenantId('1');
        $this->assertNull($this->service->find($c2->id));
        $this->assertNotNull($this->service->find($c1->id));
    }

    public function testGlobalHelper(): void
    {
        $this->tenantContext->setTenantId('1');

        $helperInstance = contacts();
        $this->assertInstanceOf(ContactRegistryInterface::class, $helperInstance);

        $created = $helperInstance->create(['name' => 'Via Helper Contact']);
        $this->assertSame('Via Helper Contact', $created->name);
    }
}
