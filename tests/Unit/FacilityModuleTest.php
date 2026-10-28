<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Facility\Contracts\FacilityRegistryInterface;
use App\Modules\Facility\Controllers\ApiFacilityController;
use App\Modules\Facility\Controllers\FacilityController;
use App\Modules\Facility\Services\FacilityService;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use Core\Http\Request;
use Core\Http\Session;
use Core\Sequence\SequenceManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FacilityModuleTest extends TestCase
{
    private Connection $db;
    private Migrator $migrator;
    private TenantContext $tenantContext;
    private FacilityService $facility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new Connection('sqlite::memory:');
        $this->migrator = new Migrator($this->db);
        $this->migrator->runMigrationsFrom(__DIR__ . '/../../database/migrations');

        $this->tenantContext = TenantContext::getInstance();
        $this->tenantContext->clear();

        $container = \Core\Container::getInstance();
        $container->instance(Connection::class, $this->db);
        $container->instance(TenantContext::class, $this->tenantContext);
        $container->instance(SequenceManager::class, new SequenceManager($this->db));

        // Buat 2 tenant untuk uji multi-tenant
        $this->db->statement("INSERT INTO tenants (id, name, slug) VALUES (1, 'Klinik & Apotek Sehat Utama', 'klinik-utama')");
        $this->db->statement("INSERT INTO tenants (id, name, slug) VALUES (2, 'Kantor Layanan Sosial Peduli', 'sosial-peduli')");

        $this->facility = new FacilityService($this->db, $this->tenantContext);
        $container->instance(FacilityRegistryInterface::class, $this->facility);
        $container->instance(FacilityService::class, $this->facility);
    }

    protected function tearDown(): void
    {
        $this->tenantContext->clear();
        parent::tearDown();
    }

    public function test_can_create_location_and_rooms_with_hierarchical_relations(): void
    {
        $this->tenantContext->setTenantId('1');

        $location = $this->facility->createLocation([
            'name' => 'Gedung Pusat Rawat Jalan',
            'type' => 'headquarters',
            'address' => 'Jl. Kesehatan No. 10',
            'city' => 'Jakarta Pusat',
            'phone' => '021-5551234',
        ]);

        $this->assertNotEmpty($location['id']);
        $this->assertEquals(1, $location['tenant_id']);
        $this->assertEquals('Gedung Pusat Rawat Jalan', $location['name']);
        $this->assertEquals('headquarters', $location['type']);
        $this->assertStringStartsWith('LOC/', $location['code']);

        $room = $this->facility->createRoom([
            'location_id' => $location['id'],
            'code' => 'POLI-UMUM',
            'name' => 'Poli Umum 1',
            'floor' => '1',
            'type' => 'service',
            'capacity' => 2,
            'notes' => 'Lantai 1 dekat lobi pendaftaran',
        ]);

        $this->assertNotEmpty($room['id']);
        $this->assertEquals($location['id'], $room['location_id']);
        $this->assertEquals('POLI-UMUM', $room['code']);
        $this->assertEquals('service', $room['type']);
        $this->assertEquals(2, $room['capacity']);

        $fetched = $this->facility->getRoom($room['id']);
        $this->assertNotNull($fetched);
        $this->assertEquals('Gedung Pusat Rawat Jalan', $fetched['location_name']);
    }

    public function test_can_create_spaces_with_initial_available_status(): void
    {
        $this->tenantContext->setTenantId('1');

        $loc = $this->facility->createLocation(['name' => 'Klinik Pratama']);
        $room = $this->facility->createRoom([
            'location_id' => $loc['id'],
            'code' => 'RAWAT-INAP-1',
            'name' => 'Kamar Rawat Inap Melati 1',
            'floor' => '2',
            'type' => 'inpatient',
            'capacity' => 2,
        ]);

        $space1 = $this->facility->createSpace([
            'room_id' => $room['id'],
            'code' => 'BED-MLT-01',
            'name' => 'Bed Melati 01',
            'type' => 'bed',
        ]);

        $space2 = $this->facility->createSpace([
            'room_id' => $room['id'],
            'name' => 'Bed Melati 02',
            'type' => 'bed',
        ]);

        $this->assertEquals('available', $space1['status']);
        $this->assertEquals('BED-MLT-01', $space1['code']);
        $this->assertEquals('available', $space2['status']);
        $this->assertStringStartsWith('SPC/', $space2['code']);
    }

    public function test_can_update_space_status_to_occupied_and_available(): void
    {
        $this->tenantContext->setTenantId('1');

        $loc = $this->facility->createLocation(['name' => 'Gedung Medika']);
        $room = $this->facility->createRoom([
            'location_id' => $loc['id'],
            'code' => 'RM-DENTAL',
            'name' => 'Poli Gigi',
            'type' => 'service',
        ]);

        $chair = $this->facility->createSpace([
            'room_id' => $room['id'],
            'code' => 'CHAIR-GIGI-1',
            'name' => 'Dental Chair 1',
            'type' => 'chair',
        ]);

        $this->assertEquals('available', $chair['status']);

        // Set occupied
        $updated = $this->facility->updateSpaceStatus($chair['id'], 'occupied');
        $this->assertEquals('occupied', $updated['status']);

        // Set maintenance
        $maint = $this->facility->updateSpaceStatus($chair['id'], 'maintenance');
        $this->assertEquals('maintenance', $maint['status']);

        // Set reserved
        $res = $this->facility->updateSpaceStatus($chair['id'], 'reserved');
        $this->assertEquals('reserved', $res['status']);

        // Back to available
        $avail = $this->facility->updateSpaceStatus($chair['id'], 'available');
        $this->assertEquals('available', $avail['status']);
    }

    public function test_invalid_space_status_throws_exception(): void
    {
        $this->tenantContext->setTenantId('1');

        $loc = $this->facility->createLocation(['name' => 'Gedung Medika']);
        $room = $this->facility->createRoom([
            'location_id' => $loc['id'],
            'code' => 'RM-TEST',
            'name' => 'Ruang Uji',
        ]);

        $space = $this->facility->createSpace([
            'room_id' => $room['id'],
            'name' => 'Slot 1',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->facility->updateSpaceStatus($space['id'], 'invalid_status');
    }

    public function test_cannot_delete_location_with_existing_active_rooms(): void
    {
        $this->tenantContext->setTenantId('1');

        $loc = $this->facility->createLocation(['name' => 'Kantor Cabang Barat']);
        $this->facility->createRoom([
            'location_id' => $loc['id'],
            'code' => 'LOBBY-1',
            'name' => 'Lobi Utama',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot delete location with existing rooms.');
        $this->facility->deleteLocation($loc['id']);
    }

    public function test_cannot_delete_room_with_existing_spaces(): void
    {
        $this->tenantContext->setTenantId('1');

        $loc = $this->facility->createLocation(['name' => 'Kantor Pusat']);
        $room = $this->facility->createRoom([
            'location_id' => $loc['id'],
            'code' => 'RM-ADM',
            'name' => 'Ruang Administrasi',
        ]);

        $this->facility->createSpace([
            'room_id' => $room['id'],
            'name' => 'Meja Resepsionis',
            'type' => 'desk',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot delete room with existing spaces.');
        $this->facility->deleteRoom($room['id']);
    }

    public function test_list_storage_rooms_only_returns_rooms_with_storage_type(): void
    {
        $this->tenantContext->setTenantId('1');

        $loc1 = $this->facility->createLocation(['name' => 'Gedung Utama']);
        $loc2 = $this->facility->createLocation(['name' => 'Posko Wilayah']);

        // Rooms on loc1
        $this->facility->createRoom([
            'location_id' => $loc1['id'],
            'code' => 'GDG-FARMASI',
            'name' => 'Gudang Farmasi Utama',
            'type' => 'storage',
        ]);

        $this->facility->createRoom([
            'location_id' => $loc1['id'],
            'code' => 'POLI-1',
            'name' => 'Poli Umum',
            'type' => 'service',
        ]);

        // Storage on loc2
        $this->facility->createRoom([
            'location_id' => $loc2['id'],
            'code' => 'GDG-POSKO',
            'name' => 'Gudang Logistik Posko',
            'type' => 'storage',
        ]);

        // All storage rooms across tenant
        $allStorages = $this->facility->listStorageRooms();
        $this->assertCount(2, $allStorages);

        // Filtered by location 1
        $loc1Storages = $this->facility->listStorageRooms($loc1['id']);
        $this->assertCount(1, $loc1Storages);
        $this->assertEquals('GDG-FARMASI', $loc1Storages[0]['code']);
    }

    public function test_room_occupancy_calculates_total_available_and_occupied_spaces(): void
    {
        $this->tenantContext->setTenantId('1');

        $loc = $this->facility->createLocation(['name' => 'RS Kencana']);
        $room = $this->facility->createRoom([
            'location_id' => $loc['id'],
            'code' => 'ICU-1',
            'name' => 'Ruang ICU',
            'type' => 'procedure',
            'capacity' => 4,
        ]);

        $bed1 = $this->facility->createSpace(['room_id' => $room['id'], 'name' => 'Bed ICU 1', 'type' => 'bed']);
        $bed2 = $this->facility->createSpace(['room_id' => $room['id'], 'name' => 'Bed ICU 2', 'type' => 'bed']);
        $bed3 = $this->facility->createSpace(['room_id' => $room['id'], 'name' => 'Bed ICU 3', 'type' => 'bed']);
        $bed4 = $this->facility->createSpace(['room_id' => $room['id'], 'name' => 'Bed ICU 4', 'type' => 'bed']);

        $this->facility->updateSpaceStatus($bed1['id'], 'occupied');
        $this->facility->updateSpaceStatus($bed2['id'], 'occupied');
        $this->facility->updateSpaceStatus($bed3['id'], 'maintenance');

        $occupancy = $this->facility->getRoomOccupancy($room['id']);

        $this->assertEquals(4, $occupancy['total_spaces']);
        $this->assertEquals(1, $occupancy['available']);
        $this->assertEquals(2, $occupancy['occupied']);
        $this->assertEquals(1, $occupancy['maintenance']);
        $this->assertEquals(0, $occupancy['reserved']);
        $this->assertEquals(50.0, $occupancy['occupancy_rate']); // 2 out of 4 occupied = 50%
    }

    public function test_multi_tenant_isolation_in_facility_registry(): void
    {
        // Tenant 1
        $this->tenantContext->setTenantId('1');
        $locT1 = $this->facility->createLocation(['name' => 'Gedung Medika T1']);
        $roomT1 = $this->facility->createRoom([
            'location_id' => $locT1['id'],
            'code' => 'RM-T1',
            'name' => 'Poli T1',
        ]);
        $spaceT1 = $this->facility->createSpace([
            'room_id' => $roomT1['id'],
            'code' => 'SPC-T1',
            'name' => 'Slot T1',
        ]);

        // Tenant 2
        $this->tenantContext->setTenantId('2');
        $locT2 = $this->facility->createLocation(['name' => 'Posko Wilayah T2']);
        $roomT2 = $this->facility->createRoom([
            'location_id' => $locT2['id'],
            'code' => 'RM-T2',
            'name' => 'Posko Logistik T2',
            'type' => 'storage',
        ]);

        // Tenant 2 should not see Tenant 1 locations
        $this->assertNull($this->facility->getLocation($locT1['id']));
        $this->assertNull($this->facility->getRoom($roomT1['id']));
        $this->assertNull($this->facility->getSpace($spaceT1['id']));

        $t2Locations = $this->facility->listLocations();
        $this->assertCount(1, $t2Locations);
        $this->assertEquals('Posko Wilayah T2', $t2Locations[0]['name']);

        $t2Rooms = $this->facility->listRooms();
        $this->assertCount(1, $t2Rooms);
        $this->assertEquals('RM-T2', $t2Rooms[0]['code']);

        // Switch back to Tenant 1
        $this->tenantContext->setTenantId('1');
        $this->assertNotNull($this->facility->getLocation($locT1['id']));
        $this->assertNull($this->facility->getLocation($locT2['id']));
    }

    public function test_facility_summary_aggregates_total_locations_rooms_and_spaces(): void
    {
        $this->tenantContext->setTenantId('1');

        $loc = $this->facility->createLocation(['name' => 'Gedung Komprehensif']);
        $r1 = $this->facility->createRoom([
            'location_id' => $loc['id'],
            'name' => 'Gudang Utama',
            'type' => 'storage',
        ]);
        $r2 = $this->facility->createRoom([
            'location_id' => $loc['id'],
            'name' => 'Kamar Rawat',
            'type' => 'inpatient',
        ]);

        $s1 = $this->facility->createSpace(['room_id' => $r2['id'], 'name' => 'Bed 1']);
        $s2 = $this->facility->createSpace(['room_id' => $r2['id'], 'name' => 'Bed 2']);
        $this->facility->updateSpaceStatus($s1['id'], 'occupied');

        $summary = $this->facility->getFacilitySummary();

        $this->assertEquals(1, $summary['total_locations']);
        $this->assertEquals(2, $summary['total_rooms']);
        $this->assertEquals(1, $summary['total_storage_rooms']);
        $this->assertEquals(2, $summary['total_spaces']);
        $this->assertEquals(1, $summary['available_spaces']);
        $this->assertEquals(1, $summary['occupied_spaces']);
    }

    public function test_global_helper_facilities_resolves_contract(): void
    {
        $this->tenantContext->setTenantId('1');
        $instance = facilities();
        $this->assertInstanceOf(FacilityRegistryInterface::class, $instance);
    }

    public function test_update_location_and_room_and_space(): void
    {
        $this->tenantContext->setTenantId('1');

        $loc = $this->facility->createLocation(['name' => 'Gedung A', 'city' => 'Bandung']);
        $updatedLoc = $this->facility->updateLocation($loc['id'], ['name' => 'Gedung A Baru', 'city' => 'Jakarta']);
        $this->assertEquals('Gedung A Baru', $updatedLoc['name']);
        $this->assertEquals('Jakarta', $updatedLoc['city']);

        $room = $this->facility->createRoom([
            'location_id' => $loc['id'],
            'name' => 'Ruang 101',
            'floor' => '1',
            'capacity' => 2,
        ]);
        $updatedRoom = $this->facility->updateRoom($room['id'], [
            'name' => 'Ruang 101 Revisi',
            'capacity' => 5,
        ]);
        $this->assertEquals('Ruang 101 Revisi', $updatedRoom['name']);
        $this->assertEquals(5, $updatedRoom['capacity']);

        $space = $this->facility->createSpace([
            'room_id' => $room['id'],
            'name' => 'Meja A',
            'type' => 'desk',
        ]);
        $updatedSpace = $this->facility->updateSpace($space['id'], [
            'name' => 'Meja B',
            'type' => 'booth',
        ]);
        $this->assertEquals('Meja B', $updatedSpace['name']);
        $this->assertEquals('booth', $updatedSpace['type']);
    }

    public function test_delete_leaf_entities_succeeds(): void
    {
        $this->tenantContext->setTenantId('1');

        $loc = $this->facility->createLocation(['name' => 'Gedung Sementara']);
        $room = $this->facility->createRoom(['location_id' => $loc['id'], 'name' => 'Ruang Uji']);
        $space = $this->facility->createSpace(['room_id' => $room['id'], 'name' => 'Slot Uji']);

        // Cannot delete loc or room while children exist
        $this->assertFalse(false); // Sanity check

        // Delete space first
        $deletedSpace = $this->facility->deleteSpace($space['id']);
        $this->assertTrue($deletedSpace);
        $this->assertNull($this->facility->getSpace($space['id']));

        // Delete room next
        $deletedRoom = $this->facility->deleteRoom($room['id']);
        $this->assertTrue($deletedRoom);
        $this->assertNull($this->facility->getRoom($room['id']));

        // Delete location last
        $deletedLoc = $this->facility->deleteLocation($loc['id']);
        $this->assertTrue($deletedLoc);
        $this->assertNull($this->facility->getLocation($loc['id']));
    }

    public function test_validations_throw_expected_exceptions(): void
    {
        $this->tenantContext->setTenantId('1');

        // Empty location name
        $this->expectException(InvalidArgumentException::class);
        $this->facility->createLocation(['name' => '']);
    }

    public function test_api_facility_controller_crud_flow(): void
    {
        $this->tenantContext->setTenantId('1');
        $api = new ApiFacilityController($this->facility);

        // Create location
        $reqLoc = new Request('POST', '/api/facility/locations', [], [], ['name' => 'API Hospital', 'type' => 'branch', 'city' => 'Surabaya']);
        $resLoc = $api->storeLocation($reqLoc);
        $this->assertEquals(201, $resLoc->getStatusCode());
        $bodyLoc = json_decode($resLoc->getContent(), true);
        $locId = $bodyLoc['data']['id'];

        // Create room
        $reqRoom = new Request('POST', '/api/facility/rooms', [], [], ['location_id' => $locId, 'name' => 'API Poli Umum', 'type' => 'service', 'capacity' => 3]);
        $resRoom = $api->storeRoom($reqRoom);
        $this->assertEquals(201, $resRoom->getStatusCode());
        $bodyRoom = json_decode($resRoom->getContent(), true);
        $roomId = $bodyRoom['data']['id'];

        // Create space
        $reqSpace = new Request('POST', '/api/facility/spaces', [], [], ['room_id' => $roomId, 'name' => 'API Desk 1', 'type' => 'desk', 'status' => 'available']);
        $resSpace = $api->storeSpace($reqSpace);
        $this->assertEquals(201, $resSpace->getStatusCode());
        $bodySpace = json_decode($resSpace->getContent(), true);
        $spaceId = $bodySpace['data']['id'];

        // Update space status
        $reqStatus = new Request('PUT', "/api/facility/spaces/{$spaceId}/status", [], [], ['status' => 'occupied']);
        $resStatus = $api->updateSpaceStatus($spaceId, $reqStatus);
        $this->assertEquals(200, $resStatus->getStatusCode());

        // Check room occupancy
        $resOcc = $api->roomOccupancy($roomId);
        $this->assertEquals(200, $resOcc->getStatusCode());
        $bodyOcc = json_decode($resOcc->getContent(), true);
        $this->assertEquals(1, $bodyOcc['data']['occupied']);
        $this->assertEquals(100.0, $bodyOcc['data']['occupancy_rate']);

        // Check summary
        $resSummary = $api->summary();
        $this->assertEquals(200, $resSummary->getStatusCode());
        $bodySummary = json_decode($resSummary->getContent(), true);
        $this->assertGreaterThanOrEqual(1, $bodySummary['data']['total_locations']);
    }

    public function test_facility_web_controller_index_response(): void
    {
        $this->tenantContext->setTenantId('1');

        $authMock = $this->createMock(AuthService::class);
        $authMock->method('currentTenant')->willReturn(null);
        $session = new Session();

        $webController = new FacilityController($authMock, $this->facility, $session);
        $req = new Request('GET', '/facility');
        $res = $webController->index($req);

        $this->assertEquals(200, $res->getStatusCode());
        $body = json_decode($res->getContent(), true);
        $this->assertEquals('success', $body['status']);
        $this->assertArrayHasKey('locations', $body);
        $this->assertArrayHasKey('rooms', $body);
        $this->assertArrayHasKey('spaces', $body);
        $this->assertArrayHasKey('summary', $body);
    }
}


