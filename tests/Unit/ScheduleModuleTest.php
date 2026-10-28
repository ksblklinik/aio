<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Schedule\Contracts\ScheduleRegistryInterface;
use App\Modules\Schedule\Services\ScheduleService;
use Core\Container;
use Core\Database\Connection;
use Core\Database\Migrator;
use Core\Database\TenantContext;
use Core\Sequence\SequenceManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ScheduleModuleTest extends TestCase
{
    private Connection $db;
    private Migrator $migrator;
    private TenantContext $tenantContext;
    private ScheduleService $scheduleService;

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
        $container->instance(SequenceManager::class, new SequenceManager($this->db));

        // Buat 2 tenant untuk uji multi-tenant
        $this->db->statement("INSERT INTO tenants (id, name, slug) VALUES (1, 'Klinik Sehat Utama', 'klinik-sehat')");
        $this->db->statement("INSERT INTO tenants (id, name, slug) VALUES (2, 'Pusat Layanan Terpadu', 'layanan-terpadu')");

        // Buat user dummy
        $this->db->statement("INSERT INTO users (id, name, email, password_hash) VALUES (1, 'Dokter Budi', 'budi@example.com', 'secret')");

        // Buat fasilitas fisik dummy
        $this->db->statement("INSERT INTO facilities_locations (id, tenant_id, code, name, type, is_active) VALUES (1, 1, 'LOC-01', 'Gedung Utama', 'clinic', 1)");
        $this->db->statement("INSERT INTO facilities_rooms (id, tenant_id, location_id, code, name, floor, type, capacity, is_active) VALUES (101, 1, 1, 'POLI-01', 'Poli Umum', '1', 'service', 10, 1)");
        $this->db->statement("INSERT INTO facilities_rooms (id, tenant_id, location_id, code, name, floor, type, capacity, is_active) VALUES (205, 1, 1, 'FARM-01', 'Instalasi Farmasi', '1', 'storage', 10, 1)");
        $this->db->statement("INSERT INTO facilities_spaces (id, tenant_id, room_id, code, name, type, status, is_active) VALUES (1, 1, 101, 'MEJA-01', 'Meja Dokter 1', 'desk', 'available', 1)");

        $this->scheduleService = new ScheduleService($this->db, $this->tenantContext);
        $container->instance(ScheduleRegistryInterface::class, $this->scheduleService);
    }

    public function testCanCreateResourceScheduleAndRetrieveByDay(): void
    {
        $this->tenantContext->setTenantId('1');

        $schedule = $this->scheduleService->createSchedule([
            'day_of_week' => 1, // Senin
            'start_time' => '08:00',
            'end_time' => '12:00',
            'quota' => 25,
        ]);

        $this->assertNotNull($schedule);
        $this->assertSame(1, (int) $schedule['day_of_week']);
        $this->assertSame('08:00', $schedule['start_time']);
        $this->assertSame(25, (int) $schedule['quota']);

        $list = $this->scheduleService->listSchedules(['day_of_week' => 1]);
        $this->assertCount(1, $list);
        $this->assertSame($schedule['id'], $list[0]['id']);

        // Update kuota
        $updated = $this->scheduleService->updateSchedule((int) $schedule['id'], ['quota' => 30]);
        $this->assertSame(30, (int) $updated['quota']);

        // Delete
        $deleted = $this->scheduleService->deleteSchedule((int) $schedule['id']);
        $this->assertTrue($deleted);
        $this->assertEmpty($this->scheduleService->listSchedules(['day_of_week' => 1]));
    }

    public function testCanCreateAppointmentAndCheckInToQueue(): void
    {
        $this->tenantContext->setTenantId('1');

        $appointment = $this->scheduleService->createAppointment([
            'client_name' => 'Ahmad Santoso',
            'client_phone' => '08123456789',
            'appointment_date' => '2026-10-28',
            'start_time' => '09:00',
            'notes' => 'Konsultasi Poli Umum',
        ]);

        $this->assertNotNull($appointment);
        $this->assertStringStartsWith('APT/2026/', $appointment['appointment_number']);
        $this->assertSame('booked', $appointment['status']);
        $this->assertSame('Ahmad Santoso', $appointment['client_name']);

        // Check-in pasien saat tiba di lokasi -> otomatis menerbitkan tiket antrean prefix 'A'
        $result = $this->scheduleService->checkInAppointment((int) $appointment['id'], 'A');
        $this->assertSame('checked_in', $result['appointment']['status']);
        $this->assertNotNull($result['ticket']);
        $this->assertSame('A-001', $result['ticket']['ticket_number']);
        $this->assertSame('waiting', $result['ticket']['status']);
        $this->assertSame((int) $appointment['id'], (int) $result['ticket']['appointment_id']);
    }

    public function testIssueQueueTicketsGeneratesSequentialDailyTicketNumbers(): void
    {
        $this->tenantContext->setTenantId('1');

        // Tiket A1
        $t1 = $this->scheduleService->issueQueueTicket([
            'prefix' => 'A',
            'service_name' => 'Poli Umum',
            'client_name' => 'Pasien Walk-in 1',
        ]);
        $this->assertSame('A-001', $t1['ticket_number']);
        $this->assertSame(1, (int) $t1['sequence_number']);

        // Tiket A2
        $t2 = $this->scheduleService->issueQueueTicket([
            'prefix' => 'A',
            'service_name' => 'Poli Umum',
            'client_name' => 'Pasien Walk-in 2',
        ]);
        $this->assertSame('A-002', $t2['ticket_number']);
        $this->assertSame(2, (int) $t2['sequence_number']);

        // Tiket B1 (Prefix berbeda mandiri)
        $tb = $this->scheduleService->issueQueueTicket([
            'prefix' => 'B',
            'service_name' => 'Poli Gigi',
            'client_name' => 'Pasien Gigi 1',
        ]);
        $this->assertSame('B-001', $tb['ticket_number']);
        $this->assertSame(1, (int) $tb['sequence_number']);
    }

    public function testCallingNextTicketTransitionsStateFromWaitingToCalledWithTimestamps(): void
    {
        $this->tenantContext->setTenantId('1');

        $this->scheduleService->issueQueueTicket(['prefix' => 'A', 'client_name' => 'Pasien 1']);
        $this->scheduleService->issueQueueTicket(['prefix' => 'A', 'client_name' => 'Pasien 2']);

        // Panggil nomor berikutnya untuk prefix 'A'
        $called1 = $this->scheduleService->callNextTicket('A', roomId: 101, spaceId: 1, userId: 1);
        $this->assertNotNull($called1);
        $this->assertSame('A-001', $called1['ticket_number']);
        $this->assertSame('called', $called1['status']);
        $this->assertSame(101, (int) $called1['room_id']);
        $this->assertSame(1, (int) $called1['space_id']);
        $this->assertSame(1, (int) $called1['call_count']);
        $this->assertNotNull($called1['called_at']);

        // Panggil lagi -> panggil tiket berikutnya (A-002)
        $called2 = $this->scheduleService->callNextTicket('A', roomId: 101, spaceId: 1, userId: 1);
        $this->assertNotNull($called2);
        $this->assertSame('A-002', $called2['ticket_number']);

        // Panggil lagi ketika antrean habis -> mengembalikan null secara anggun
        $calledEmpty = $this->scheduleService->callNextTicket('A', roomId: 101, spaceId: 1, userId: 1);
        $this->assertNull($calledEmpty);
    }

    public function testRecallTicketIncrementsCallCount(): void
    {
        $this->tenantContext->setTenantId('1');

        $t = $this->scheduleService->issueQueueTicket(['prefix' => 'A', 'client_name' => 'Pasien 1']);
        $called = $this->scheduleService->callNextTicket('A');

        $this->assertSame(1, (int) $called['call_count']);

        // Recall (panggil ulang)
        $recalled = $this->scheduleService->recallTicket((int) $t['id']);
        $this->assertSame(2, (int) $recalled['call_count']);
        $this->assertSame('called', $recalled['status']);
    }

    public function testStartServingAndCompletingTicketRecordsLifecycleTimestamps(): void
    {
        $this->tenantContext->setTenantId('1');

        $t = $this->scheduleService->issueQueueTicket(['prefix' => 'A', 'client_name' => 'Pasien 1']);
        $this->scheduleService->callNextTicket('A');

        // Mulai layan
        $serving = $this->scheduleService->startServing((int) $t['id']);
        $this->assertSame('serving', $serving['status']);
        $this->assertNotNull($serving['serving_at']);

        // Selesai
        $completed = $this->scheduleService->completeTicket((int) $t['id'], 'Selesai konsultasi dokter');
        $this->assertSame('completed', $completed['status']);
        $this->assertNotNull($completed['completed_at']);
        $this->assertStringContainsString('Selesai konsultasi', $completed['notes']);
    }

    public function testSkipTicketUpdatesStatus(): void
    {
        $this->tenantContext->setTenantId('1');

        $t = $this->scheduleService->issueQueueTicket(['prefix' => 'A', 'client_name' => 'Pasien Skip']);
        $this->scheduleService->callNextTicket('A');

        $skipped = $this->scheduleService->skipTicket((int) $t['id'], 'Tidak menyahut 3x panggilan');
        $this->assertSame('skipped', $skipped['status']);
        $this->assertStringContainsString('Tidak menyahut', $skipped['notes']);
    }

    public function testTransferTicketToAnotherServiceCreatesConnectedSequence(): void
    {
        $this->tenantContext->setTenantId('1');

        $tPoli = $this->scheduleService->issueQueueTicket([
            'prefix' => 'A',
            'service_name' => 'Poli Umum',
            'client_name' => 'Pasien Transfer',
        ]);
        $this->scheduleService->callNextTicket('A');
        $this->scheduleService->startServing((int) $tPoli['id']);
        $this->scheduleService->completeTicket((int) $tPoli['id']);

        // Transfer ke Farmasi (Prefix 'F')
        $tFarmasi = $this->scheduleService->transferTicket((int) $tPoli['id'], 'F', targetRoomId: 205, notes: 'Tebus resep obat');
        $this->assertNotNull($tFarmasi);
        $this->assertSame('F-001', $tFarmasi['ticket_number']);
        $this->assertSame('waiting', $tFarmasi['status']);
        $this->assertSame('Pasien Transfer', $tFarmasi['client_name']);
        $this->assertStringContainsString('Transfer dari tiket A-001', $tFarmasi['notes']);
    }

    public function testDisplayQueueStateReturnsActiveTicketsAndWaitingCount(): void
    {
        $this->tenantContext->setTenantId('1');

        // Masukkan beberapa tiket
        $this->scheduleService->issueQueueTicket(['prefix' => 'A', 'service_name' => 'Poli Umum', 'client_name' => 'P1']);
        $this->scheduleService->issueQueueTicket(['prefix' => 'A', 'service_name' => 'Poli Umum', 'client_name' => 'P2']);
        $this->scheduleService->issueQueueTicket(['prefix' => 'B', 'service_name' => 'Poli Gigi', 'client_name' => 'G1']);

        // Panggil tiket A1 di loket 1
        $this->scheduleService->callNextTicket('A', roomId: 101, spaceId: 1);

        $display = $this->scheduleService->getDisplayQueueState();
        $this->assertArrayHasKey('called_tickets', $display);
        $this->assertArrayHasKey('services_summary', $display);
        $this->assertArrayHasKey('latest_called', $display);

        $this->assertSame('A-001', $display['latest_called']['ticket_number']);
        $this->assertStringContainsString('A-001', $display['latest_called']['tts_announcement']);

        // Waiting count untuk Poli Umum sisa 1 (P2)
        $waitingA = $this->scheduleService->listWaitingTickets('A');
        $this->assertCount(1, $waitingA);
        $this->assertSame('A-002', $waitingA[0]['ticket_number']);
    }

    public function testMultiTenantIsolationInSchedulingAndQueues(): void
    {
        // Tenant 1 buat tiket A-001
        $this->tenantContext->setTenantId('1');
        $t1 = $this->scheduleService->issueQueueTicket(['prefix' => 'A', 'client_name' => 'Klien T1']);
        $this->assertSame('A-001', $t1['ticket_number']);

        // Tenant 2 buat tiket A-001 (nomor mandiri di tenant 2)
        $this->tenantContext->setTenantId('2');
        $t2 = $this->scheduleService->issueQueueTicket(['prefix' => 'A', 'client_name' => 'Klien T2']);
        $this->assertSame('A-001', $t2['ticket_number']);

        // Tenant 2 tidak bisa melihat tiket Tenant 1
        $this->assertNull($this->scheduleService->getQueueTicket((int) $t1['id']));
        $this->assertNotNull($this->scheduleService->getQueueTicket((int) $t2['id']));

        // Tenant 2 memanggil antrean -> memanggil t2, bukan t1
        $calledT2 = $this->scheduleService->callNextTicket('A');
        $this->assertSame((int) $t2['id'], (int) $calledT2['id']);
        $this->assertSame('Klien T2', $calledT2['client_name']);
    }

    public function testQueueOperationsPublishSseEvents(): void
    {
        $this->tenantContext->setTenantId('1');
        $broadcaster = new \Core\Broadcasting\SseBroadcaster($this->db, $this->tenantContext);
        \Core\Container::getInstance()->instance(\Core\Broadcasting\SseBroadcasterInterface::class, $broadcaster);

        $t = $this->scheduleService->issueQueueTicket(['prefix' => 'A', 'client_name' => 'Pasien SSE']);
        $this->scheduleService->callNextTicket('A', roomId: 101);

        $events = $broadcaster->getEvents('queue');
        $this->assertNotEmpty($events);

        $eventNames = array_column($events, 'event');
        $this->assertContains('ticket_issued', $eventNames);
        $this->assertContains('ticket_called', $eventNames);

        $calledEvents = array_filter($events, fn($e) => $e['event'] === 'ticket_called');
        $calledEvent = reset($calledEvents);
        $this->assertEquals('A-001', $calledEvent['payload']['ticket_number']);
        $this->assertStringContainsString('Nomor antrean', $calledEvent['payload']['tts_announcement']);
    }
}

