<?php

declare(strict_types=1);

namespace App\Modules\Schedule\Services;

use App\Modules\Schedule\Contracts\ScheduleRegistryInterface;
use Core\Database\Connection;
use Core\Database\TenantContext;
use InvalidArgumentException;
use RuntimeException;

class ScheduleService implements ScheduleRegistryInterface
{
    public function __construct(
        private Connection $db,
        private ?TenantContext $tenantContext = null
    ) {
    }

    private function getTenantId(): int
    {
        $id = $this->tenantContext?->getTenantId();
        if ($id !== null && is_numeric($id)) {
            return (int) $id;
        }

        return 1;
    }

    // ==========================================
    // 1. Resource Schedule Management
    // ==========================================

    public function createSchedule(array $data): array
    {
        $tenantId = $this->getTenantId();

        if (!isset($data['day_of_week'])) {
            throw new InvalidArgumentException('Hari kerja (day_of_week: 0-6) wajib diisi.');
        }

        $id = (int) $this->db->insert('resource_schedules', [
            'tenant_id' => $tenantId,
            'staff_contact_id' => !empty($data['staff_contact_id']) ? (int) $data['staff_contact_id'] : null,
            'room_id' => !empty($data['room_id']) ? (int) $data['room_id'] : null,
            'service_item_id' => !empty($data['service_item_id']) ? (int) $data['service_item_id'] : null,
            'day_of_week' => (int) $data['day_of_week'],
            'start_time' => (string) ($data['start_time'] ?? '08:00'),
            'end_time' => (string) ($data['end_time'] ?? '12:00'),
            'quota' => (int) ($data['quota'] ?? 30),
            'is_active' => isset($data['is_active']) ? (int) (bool) $data['is_active'] : 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->getSchedule($id) ?? [];
    }

    public function getSchedule(int $id): ?array
    {
        $tenantId = $this->getTenantId();
        $sql = "SELECT rs.*,
                       c.name AS staff_name,
                       r.name AS room_name,
                       r.code AS room_code,
                       i.name AS service_name
                FROM resource_schedules rs
                LEFT JOIN contacts c ON c.id = rs.staff_contact_id
                LEFT JOIN facilities_rooms r ON r.id = rs.room_id
                LEFT JOIN items i ON i.id = rs.service_item_id
                WHERE rs.id = ? AND rs.tenant_id = ?";

        $row = $this->db->selectOne($sql, [$id, $tenantId]);
        return $row ? $this->castScheduleRow($row) : null;
    }

    public function updateSchedule(int $id, array $data): array
    {
        $tenantId = $this->getTenantId();
        $existing = $this->getSchedule($id);
        if (!$existing) {
            throw new RuntimeException("Jadwal dengan ID $id tidak ditemukan.");
        }

        $updates = ['updated_at' => date('Y-m-d H:i:s')];
        if (isset($data['staff_contact_id'])) {
            $updates['staff_contact_id'] = $data['staff_contact_id'] ? (int) $data['staff_contact_id'] : null;
        }
        if (isset($data['room_id'])) {
            $updates['room_id'] = $data['room_id'] ? (int) $data['room_id'] : null;
        }
        if (isset($data['service_item_id'])) {
            $updates['service_item_id'] = $data['service_item_id'] ? (int) $data['service_item_id'] : null;
        }
        if (isset($data['day_of_week'])) {
            $updates['day_of_week'] = (int) $data['day_of_week'];
        }
        if (isset($data['start_time'])) {
            $updates['start_time'] = (string) $data['start_time'];
        }
        if (isset($data['end_time'])) {
            $updates['end_time'] = (string) $data['end_time'];
        }
        if (isset($data['quota'])) {
            $updates['quota'] = (int) $data['quota'];
        }
        if (isset($data['is_active'])) {
            $updates['is_active'] = (int) (bool) $data['is_active'];
        }

        $this->db->update('resource_schedules', $updates, 'id = ? AND tenant_id = ?', [$id, $tenantId]);
        return $this->getSchedule($id) ?? [];
    }

    public function deleteSchedule(int $id): bool
    {
        $tenantId = $this->getTenantId();
        $deleted = $this->db->delete('resource_schedules', 'id = ? AND tenant_id = ?', [$id, $tenantId]);
        return $deleted > 0;
    }

    public function listSchedules(array $filters = []): array
    {
        $tenantId = $this->getTenantId();
        $sql = "SELECT rs.*,
                       c.name AS staff_name,
                       r.name AS room_name,
                       r.code AS room_code,
                       i.name AS service_name
                FROM resource_schedules rs
                LEFT JOIN contacts c ON c.id = rs.staff_contact_id
                LEFT JOIN facilities_rooms r ON r.id = rs.room_id
                LEFT JOIN items i ON i.id = rs.service_item_id
                WHERE rs.tenant_id = ?";
        $params = [$tenantId];

        if (isset($filters['day_of_week']) && $filters['day_of_week'] !== '') {
            $sql .= " AND rs.day_of_week = ?";
            $params[] = (int) $filters['day_of_week'];
        }
        if (!empty($filters['staff_contact_id'])) {
            $sql .= " AND rs.staff_contact_id = ?";
            $params[] = (int) $filters['staff_contact_id'];
        }
        if (!empty($filters['room_id'])) {
            $sql .= " AND rs.room_id = ?";
            $params[] = (int) $filters['room_id'];
        }
        if (isset($filters['is_active'])) {
            $sql .= " AND rs.is_active = ?";
            $params[] = (int) (bool) $filters['is_active'];
        }

        $sql .= " ORDER BY rs.day_of_week ASC, rs.start_time ASC";
        $rows = $this->db->selectAll($sql, $params);
        return array_map([$this, 'castScheduleRow'], $rows);
    }

    private function castScheduleRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['tenant_id'] = (int) $row['tenant_id'];
        $row['staff_contact_id'] = !empty($row['staff_contact_id']) ? (int) $row['staff_contact_id'] : null;
        $row['room_id'] = !empty($row['room_id']) ? (int) $row['room_id'] : null;
        $row['service_item_id'] = !empty($row['service_item_id']) ? (int) $row['service_item_id'] : null;
        $row['day_of_week'] = (int) $row['day_of_week'];
        $row['quota'] = (int) $row['quota'];
        $row['is_active'] = (bool) $row['is_active'];
        return $row;
    }

    // ==========================================
    // 2. Appointment Booking Management
    // ==========================================

    public function createAppointment(array $data): array
    {
        $tenantId = $this->getTenantId();

        if (empty($data['client_name'])) {
            throw new InvalidArgumentException('Nama klien/pasien wajib diisi.');
        }

        $appointmentNumber = !empty($data['appointment_number'])
            ? (string) $data['appointment_number']
            : $this->generateAppointmentNumber();

        $appointmentDate = !empty($data['appointment_date'])
            ? (string) $data['appointment_date']
            : date('Y-m-d');

        $startTime = !empty($data['start_time'])
            ? (string) $data['start_time']
            : '09:00';

        $id = (int) $this->db->insert('appointments', [
            'tenant_id' => $tenantId,
            'appointment_number' => $appointmentNumber,
            'client_contact_id' => !empty($data['client_contact_id']) ? (int) $data['client_contact_id'] : null,
            'client_name' => (string) $data['client_name'],
            'client_phone' => !empty($data['client_phone']) ? (string) $data['client_phone'] : null,
            'client_email' => !empty($data['client_email']) ? (string) $data['client_email'] : null,
            'staff_contact_id' => !empty($data['staff_contact_id']) ? (int) $data['staff_contact_id'] : null,
            'room_id' => !empty($data['room_id']) ? (int) $data['room_id'] : null,
            'service_item_id' => !empty($data['service_item_id']) ? (int) $data['service_item_id'] : null,
            'appointment_date' => $appointmentDate,
            'start_time' => $startTime,
            'end_time' => !empty($data['end_time']) ? (string) $data['end_time'] : null,
            'status' => (string) ($data['status'] ?? 'booked'),
            'notes' => !empty($data['notes']) ? (string) $data['notes'] : null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->getAppointment($id) ?? [];
    }

    private function generateAppointmentNumber(): string
    {
        if (function_exists('sequence')) {
            try {
                return sequence('appointment', 'APT', 5, 'yearly');
            } catch (\Throwable) {
                // fallback below
            }
        }

        $tenantId = $this->getTenantId();
        $year = date('Y');
        $prefix = "APT/{$year}/";
        $row = $this->db->selectOne(
            "SELECT appointment_number FROM appointments 
             WHERE tenant_id = ? AND appointment_number LIKE ? 
             ORDER BY id DESC LIMIT 1",
            [$tenantId, "{$prefix}%"]
        );

        $nextSeq = 1;
        if ($row && preg_match('/APT\/\d{4}\/(\d+)/', (string) $row['appointment_number'], $matches)) {
            $nextSeq = (int) $matches[1] + 1;
        }

        return sprintf('%s%05d', $prefix, $nextSeq);
    }

    public function getAppointment(int $id): ?array
    {
        $tenantId = $this->getTenantId();
        $sql = "SELECT a.*,
                       sc.name AS staff_name,
                       cc.name AS registered_client_name,
                       r.name AS room_name,
                       r.code AS room_code,
                       i.name AS service_name
                FROM appointments a
                LEFT JOIN contacts sc ON sc.id = a.staff_contact_id
                LEFT JOIN contacts cc ON cc.id = a.client_contact_id
                LEFT JOIN facilities_rooms r ON r.id = a.room_id
                LEFT JOIN items i ON i.id = a.service_item_id
                WHERE a.id = ? AND a.tenant_id = ?";

        $row = $this->db->selectOne($sql, [$id, $tenantId]);
        return $row ? $this->castAppointmentRow($row) : null;
    }

    public function getAppointmentByNumber(string $number): ?array
    {
        $tenantId = $this->getTenantId();
        $sql = "SELECT a.*,
                       sc.name AS staff_name,
                       cc.name AS registered_client_name,
                       r.name AS room_name,
                       r.code AS room_code,
                       i.name AS service_name
                FROM appointments a
                LEFT JOIN contacts sc ON sc.id = a.staff_contact_id
                LEFT JOIN contacts cc ON cc.id = a.client_contact_id
                LEFT JOIN facilities_rooms r ON r.id = a.room_id
                LEFT JOIN items i ON i.id = a.service_item_id
                WHERE a.appointment_number = ? AND a.tenant_id = ?";

        $row = $this->db->selectOne($sql, [$number, $tenantId]);
        return $row ? $this->castAppointmentRow($row) : null;
    }

    public function updateAppointment(int $id, array $data): array
    {
        $tenantId = $this->getTenantId();
        $existing = $this->getAppointment($id);
        if (!$existing) {
            throw new RuntimeException("Appointment ID $id tidak ditemukan.");
        }

        $updates = ['updated_at' => date('Y-m-d H:i:s')];
        $fields = [
            'client_contact_id', 'client_name', 'client_phone', 'client_email',
            'staff_contact_id', 'room_id', 'service_item_id', 'appointment_date',
            'start_time', 'end_time', 'status', 'notes', 'cancellation_reason'
        ];

        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $updates[$f] = $data[$f];
            }
        }

        $this->db->update('appointments', $updates, 'id = ? AND tenant_id = ?', [$id, $tenantId]);
        return $this->getAppointment($id) ?? [];
    }

    public function checkInAppointment(int $id, ?string $targetPrefix = null, ?int $roomId = null): array
    {
        $apt = $this->getAppointment($id);
        if (!$apt) {
            throw new RuntimeException("Appointment ID $id tidak ditemukan.");
        }

        $prefix = strtoupper($targetPrefix ?? 'A');
        $effectiveRoomId = $roomId ?? $apt['room_id'];

        // Terbitkan tiket antrean
        $ticket = $this->issueQueueTicket([
            'prefix' => $prefix,
            'service_name' => $apt['service_name'] ?? 'Layanan Perjanjian',
            'room_id' => $effectiveRoomId,
            'client_contact_id' => $apt['client_contact_id'],
            'client_name' => $apt['client_name'],
            'appointment_id' => $id,
            'notes' => "Check-in Appointment {$apt['appointment_number']}",
        ]);

        // Update appointment status to 'checked_in'
        $updatedApt = $this->updateAppointment($id, [
            'status' => 'checked_in',
        ]);

        return [
            'appointment' => $updatedApt,
            'ticket' => $ticket,
        ];
    }

    public function listAppointments(array $filters = []): array
    {
        $tenantId = $this->getTenantId();
        $sql = "SELECT a.*,
                       sc.name AS staff_name,
                       r.name AS room_name,
                       r.code AS room_code,
                       i.name AS service_name
                FROM appointments a
                LEFT JOIN contacts sc ON sc.id = a.staff_contact_id
                LEFT JOIN facilities_rooms r ON r.id = a.room_id
                LEFT JOIN items i ON i.id = a.service_item_id
                WHERE a.tenant_id = ?";
        $params = [$tenantId];

        if (!empty($filters['date'])) {
            $sql .= " AND a.appointment_date = ?";
            $params[] = (string) $filters['date'];
        }
        if (!empty($filters['status'])) {
            $sql .= " AND a.status = ?";
            $params[] = (string) $filters['status'];
        }
        if (!empty($filters['staff_contact_id'])) {
            $sql .= " AND a.staff_contact_id = ?";
            $params[] = (int) $filters['staff_contact_id'];
        }
        if (!empty($filters['client_contact_id'])) {
            $sql .= " AND a.client_contact_id = ?";
            $params[] = (int) $filters['client_contact_id'];
        }
        if (!empty($filters['room_id'])) {
            $sql .= " AND a.room_id = ?";
            $params[] = (int) $filters['room_id'];
        }

        $sql .= " ORDER BY a.appointment_date ASC, a.start_time ASC";
        $rows = $this->db->selectAll($sql, $params);
        return array_map([$this, 'castAppointmentRow'], $rows);
    }

    public function cancelAppointment(int $id, ?string $reason = null): array
    {
        return $this->updateAppointment($id, [
            'status' => 'cancelled',
            'cancellation_reason' => $reason,
        ]);
    }

    private function castAppointmentRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['tenant_id'] = (int) $row['tenant_id'];
        $row['client_contact_id'] = !empty($row['client_contact_id']) ? (int) $row['client_contact_id'] : null;
        $row['staff_contact_id'] = !empty($row['staff_contact_id']) ? (int) $row['staff_contact_id'] : null;
        $row['room_id'] = !empty($row['room_id']) ? (int) $row['room_id'] : null;
        $row['service_item_id'] = !empty($row['service_item_id']) ? (int) $row['service_item_id'] : null;
        return $row;
    }

    // ==========================================
    // 3. Human Queue Engine (Antrean Fisik)
    // ==========================================

    public function issueQueueTicket(array $data): array
    {
        $tenantId = $this->getTenantId();
        $date = !empty($data['ticket_date']) ? (string) $data['ticket_date'] : date('Y-m-d');
        $prefix = strtoupper((string) ($data['prefix'] ?? 'A'));

        $issueCallback = function () use ($data, $tenantId, $date, $prefix): array {
            // Sequence nomor urut harian per prefix per tenant
            $rowSeq = $this->db->selectOne(
                "SELECT COALESCE(MAX(sequence_number), 0) + 1 AS next_seq
                 FROM queue_tickets
                 WHERE tenant_id = ? AND ticket_date = ? AND prefix = ?",
                [$tenantId, $date, $prefix]
            );
            $nextSeq = (int) ($rowSeq['next_seq'] ?? 1);
            $ticketNumber = sprintf('%s-%03d', $prefix, $nextSeq);

            $id = (int) $this->db->insert('queue_tickets', [
                'tenant_id' => $tenantId,
                'ticket_number' => $ticketNumber,
                'prefix' => $prefix,
                'sequence_number' => $nextSeq,
                'ticket_date' => $date,
                'service_name' => (string) ($data['service_name'] ?? 'Poli Umum'),
                'room_id' => !empty($data['room_id']) ? (int) $data['room_id'] : null,
                'space_id' => !empty($data['space_id']) ? (int) $data['space_id'] : null,
                'client_contact_id' => !empty($data['client_contact_id']) ? (int) $data['client_contact_id'] : null,
                'client_name' => (string) ($data['client_name'] ?? 'Pengunjung Umum'),
                'appointment_id' => !empty($data['appointment_id']) ? (int) $data['appointment_id'] : null,
                'status' => 'waiting',
                'call_count' => 0,
                'notes' => !empty($data['notes']) ? (string) $data['notes'] : null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $ticket = $this->getQueueTicket($id) ?? [];
            try {
                if (function_exists('sse') && !empty($ticket)) {
                    sse('queue', 'ticket_issued', [
                        'ticket_id' => $id,
                        'ticket_number' => $ticketNumber,
                        'prefix' => $prefix,
                        'service_name' => (string) ($data['service_name'] ?? 'Poli Umum'),
                        'status' => 'waiting',
                    ], (string) $tenantId);
                }
            } catch (\Throwable) {}

            return $ticket;
        };

        if (function_exists('lock')) {
            $lockManager = null;
            try {
                $lockManager = lock();
            } catch (\Throwable) {
                $lockManager = null;
            }

            if ($lockManager instanceof \Core\Lock\LockManagerInterface) {
                try {
                    return (array) $lockManager->run("queue:issue:{$tenantId}:{$prefix}:{$date}", $issueCallback, ttlSeconds: 5, waitTimeoutMs: 3000);
                } catch (\Core\Lock\LockTimeoutException $e) {
                    throw new RuntimeException("Penerbitan antrean prefix '{$prefix}' sedang sibuk. Silakan coba kembali dalam beberapa saat.", 0, $e);
                }
            }
        }

        return $issueCallback();
    }

    public function getQueueTicket(int $id): ?array
    {
        $tenantId = $this->getTenantId();
        $sql = "SELECT q.*,
                       r.name AS room_name,
                       r.code AS room_code,
                       s.name AS space_name,
                       s.code AS space_code,
                       u.name AS called_by_name
                FROM queue_tickets q
                LEFT JOIN facilities_rooms r ON r.id = q.room_id
                LEFT JOIN facilities_spaces s ON s.id = q.space_id
                LEFT JOIN users u ON u.id = q.called_by
                WHERE q.id = ? AND q.tenant_id = ?";

        $row = $this->db->selectOne($sql, [$id, $tenantId]);
        return $row ? $this->castTicketRow($row) : null;
    }

    public function getQueueTicketByNumber(string $ticketNumber, ?string $date = null): ?array
    {
        $tenantId = $this->getTenantId();
        $date = $date ?? date('Y-m-d');

        $sql = "SELECT q.*,
                       r.name AS room_name,
                       r.code AS room_code,
                       s.name AS space_name,
                       s.code AS space_code,
                       u.name AS called_by_name
                FROM queue_tickets q
                LEFT JOIN facilities_rooms r ON r.id = q.room_id
                LEFT JOIN facilities_spaces s ON s.id = q.space_id
                LEFT JOIN users u ON u.id = q.called_by
                WHERE q.ticket_number = ? AND q.ticket_date = ? AND q.tenant_id = ?";

        $row = $this->db->selectOne($sql, [$ticketNumber, $date, $tenantId]);
        return $row ? $this->castTicketRow($row) : null;
    }

    public function callNextTicket(string $prefix, ?int $roomId = null, ?int $spaceId = null, ?int $userId = null): ?array
    {
        $tenantId = $this->getTenantId();
        $date = date('Y-m-d');
        $prefix = strtoupper($prefix);

        $callCallback = function () use ($tenantId, $date, $prefix, $roomId, $spaceId, $userId): ?array {
            $maxRetries = 3;
            $ticket = null;
            $now = date('Y-m-d H:i:s');

            for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
                $nextTicket = $this->db->selectOne(
                    "SELECT id FROM queue_tickets
                     WHERE tenant_id = ? AND ticket_date = ? AND prefix = ? AND status = 'waiting'
                     ORDER BY sequence_number ASC LIMIT 1",
                    [$tenantId, $date, $prefix]
                );

                if (!$nextTicket) {
                    return null;
                }

                $ticketId = (int) $nextTicket['id'];

                $affected = $this->db->affectingStatement(
                    "UPDATE queue_tickets SET 
                        status = 'called', called_at = ?, call_count = 1, updated_at = ?,
                        room_id = ?, space_id = ?, called_by = ?
                     WHERE id = ? AND tenant_id = ? AND status = 'waiting'",
                    [$now, $now, $roomId, $spaceId, $userId, $ticketId, $tenantId]
                );

                if ($affected === 1) {
                    $ticket = $this->getQueueTicket($ticketId);
                    break;
                }
            }

            if (!$ticket) {
                return null;
            }
            if ($ticket) {
                try {
                    if (function_exists('sse')) {
                        $tts = sprintf(
                            'Nomor antrean %s, silakan menuju ke %s',
                            implode(' ', str_split($ticket['ticket_number'])),
                            $ticket['room_name'] ?? ($ticket['service_name'] ?? 'ruang pelayanan')
                        );
                        sse('queue', 'ticket_called', [
                            'ticket_id' => $ticket['id'],
                            'ticket_number' => $ticket['ticket_number'],
                            'prefix' => $ticket['prefix'],
                            'service_name' => $ticket['service_name'],
                            'room_name' => $ticket['room_name'] ?? null,
                            'space_name' => $ticket['space_name'] ?? null,
                            'status' => 'called',
                            'called_at' => $ticket['called_at'] ?? $now,
                            'call_count' => $ticket['call_count'],
                            'tts_announcement' => $tts,
                        ], (string) $tenantId);
                    }
                } catch (\Throwable) {}
            }

            return $ticket;
        };

        if (function_exists('lock')) {
            $lockManager = null;
            try {
                $lockManager = lock();
            } catch (\Throwable) {
                $lockManager = null;
            }

            if ($lockManager instanceof \Core\Lock\LockManagerInterface) {
                try {
                    return $lockManager->run("queue:call:{$tenantId}:{$prefix}:{$date}", $callCallback, ttlSeconds: 5, waitTimeoutMs: 3000);
                } catch (\Core\Lock\LockTimeoutException $e) {
                    throw new RuntimeException("Pemanggilan antrean prefix '{$prefix}' sedang sibuk. Silakan coba kembali dalam beberapa saat.", 0, $e);
                }
            }
        }

        return $callCallback();
    }

    public function recallTicket(int $ticketId): array
    {
        $tenantId = $this->getTenantId();
        $ticket = $this->getQueueTicket($ticketId);
        if (!$ticket) {
            throw new RuntimeException("Tiket ID $ticketId tidak ditemukan.");
        }

        $now = date('Y-m-d H:i:s');
        $this->db->statement(
            "UPDATE queue_tickets 
             SET status = 'called', called_at = ?, call_count = call_count + 1, updated_at = ?
             WHERE id = ? AND tenant_id = ?",
            [$now, $now, $ticketId, $tenantId]
        );

        $recalled = $this->getQueueTicket($ticketId) ?? [];
        if (!empty($recalled)) {
            try {
                if (function_exists('sse')) {
                    $tts = sprintf(
                        'Panggilan ulang. Nomor antrean %s, silakan menuju ke %s',
                        implode(' ', str_split($recalled['ticket_number'])),
                        $recalled['room_name'] ?? ($recalled['service_name'] ?? 'ruang pelayanan')
                    );
                    sse('queue', 'ticket_called', [
                        'ticket_id' => $recalled['id'],
                        'ticket_number' => $recalled['ticket_number'],
                        'prefix' => $recalled['prefix'],
                        'service_name' => $recalled['service_name'],
                        'room_name' => $recalled['room_name'] ?? null,
                        'space_name' => $recalled['space_name'] ?? null,
                        'status' => 'called',
                        'called_at' => $recalled['called_at'] ?? $now,
                        'call_count' => $recalled['call_count'],
                        'tts_announcement' => $tts,
                    ], (string) $tenantId);
                }
            } catch (\Throwable) {}
        }

        return $recalled;
    }

    public function startServing(int $ticketId): array
    {
        $tenantId = $this->getTenantId();
        $ticket = $this->getQueueTicket($ticketId);
        if (!$ticket) {
            throw new RuntimeException("Tiket ID $ticketId tidak ditemukan.");
        }

        $now = date('Y-m-d H:i:s');
        $this->db->update('queue_tickets', [
            'status' => 'serving',
            'serving_at' => $now,
            'updated_at' => $now,
        ], 'id = ? AND tenant_id = ?', [$ticketId, $tenantId]);

        return $this->getQueueTicket($ticketId) ?? [];
    }

    public function completeTicket(int $ticketId, ?string $notes = null): array
    {
        $tenantId = $this->getTenantId();
        $ticket = $this->getQueueTicket($ticketId);
        if (!$ticket) {
            throw new RuntimeException("Tiket ID $ticketId tidak ditemukan.");
        }

        $now = date('Y-m-d H:i:s');
        $updates = [
            'status' => 'completed',
            'completed_at' => $now,
            'updated_at' => $now,
        ];
        if ($notes !== null) {
            $existingNotes = $ticket['notes'] ?? '';
            $updates['notes'] = $existingNotes ? "{$existingNotes}\n{$notes}" : $notes;
        }

        $this->db->update('queue_tickets', $updates, 'id = ? AND tenant_id = ?', [$ticketId, $tenantId]);

        return $this->getQueueTicket($ticketId) ?? [];
    }

    public function skipTicket(int $ticketId, ?string $reason = null): array
    {
        $tenantId = $this->getTenantId();
        $ticket = $this->getQueueTicket($ticketId);
        if (!$ticket) {
            throw new RuntimeException("Tiket ID $ticketId tidak ditemukan.");
        }

        $now = date('Y-m-d H:i:s');
        $updates = [
            'status' => 'skipped',
            'updated_at' => $now,
        ];
        if ($reason !== null) {
            $existingNotes = $ticket['notes'] ?? '';
            $updates['notes'] = $existingNotes ? "{$existingNotes}\n[Skipped: $reason]" : "[Skipped: $reason]";
        }

        $this->db->update('queue_tickets', $updates, 'id = ? AND tenant_id = ?', [$ticketId, $tenantId]);

        return $this->getQueueTicket($ticketId) ?? [];
    }

    public function transferTicket(int $ticketId, string $targetPrefix, ?int $targetRoomId = null, ?string $notes = null): array
    {
        $ticket = $this->getQueueTicket($ticketId);
        if (!$ticket) {
            throw new RuntimeException("Tiket ID $ticketId tidak ditemukan.");
        }

        // Tandai tiket lama
        $now = date('Y-m-d H:i:s');
        $this->db->update('queue_tickets', [
            'status' => 'transferred',
            'updated_at' => $now,
        ], 'id = ? AND tenant_id = ?', [$ticketId, $this->getTenantId()]);

        // Terbitkan tiket baru di layanan tujuan
        $transferNotes = "Transfer dari tiket {$ticket['ticket_number']}";
        if ($notes !== null) {
            $transferNotes .= " - {$notes}";
        }

        return $this->issueQueueTicket([
            'prefix' => $targetPrefix,
            'service_name' => "Lanjutan {$ticket['service_name']}",
            'room_id' => $targetRoomId ?? $ticket['room_id'],
            'client_contact_id' => $ticket['client_contact_id'],
            'client_name' => $ticket['client_name'],
            'appointment_id' => $ticket['appointment_id'],
            'notes' => $transferNotes,
        ]);
    }

    // ==========================================
    // 4. Live Display & Queue Analytics
    // ==========================================

    public function getDisplayQueueState(?string $date = null): array
    {
        $tenantId = $this->getTenantId();
        $date = $date ?? date('Y-m-d');

        // Ambil tiket yang baru saja dipanggil atau sedang dilayani
        $sqlLatest = "SELECT q.*,
                             r.name AS room_name,
                             r.code AS room_code,
                             s.name AS space_name,
                             s.code AS space_code
                      FROM queue_tickets q
                      LEFT JOIN facilities_rooms r ON r.id = q.room_id
                      LEFT JOIN facilities_spaces s ON s.id = q.space_id
                      WHERE q.tenant_id = ? AND q.ticket_date = ? AND q.status IN ('called', 'serving')
                      ORDER BY q.called_at DESC, q.id DESC
                      LIMIT 1";

        $latestRow = $this->db->selectOne($sqlLatest, [$tenantId, $date]);
        $latestCalled = null;

        if ($latestRow) {
            $latestRow = $this->castTicketRow($latestRow);
            $locationLabel = $latestRow['room_name'] ?? 'Loket Pelayanan';
            if (!empty($latestRow['space_name'])) {
                $locationLabel .= ' ' . $latestRow['space_name'];
            }
            $latestRow['tts_announcement'] = sprintf(
                'Nomor antrean %s, silakan menuju ke %s',
                $latestRow['ticket_number'],
                $locationLabel
            );
            $latestCalled = $latestRow;
        }

        // Daftar tiket aktif yang sedang dipanggil/dilayani per loket
        $sqlActive = "SELECT q.*,
                             r.name AS room_name,
                             r.code AS room_code,
                             s.name AS space_name,
                             s.code AS space_code
                      FROM queue_tickets q
                      LEFT JOIN facilities_rooms r ON r.id = q.room_id
                      LEFT JOIN facilities_spaces s ON s.id = q.space_id
                      WHERE q.tenant_id = ? AND q.ticket_date = ? AND q.status IN ('called', 'serving')
                      ORDER BY q.called_at DESC
                      LIMIT 10";
        $activeRows = $this->db->selectAll($sqlActive, [$tenantId, $date]);
        $calledTickets = array_map([$this, 'castTicketRow'], $activeRows);

        // Ringkasan per prefix/layanan: sisa antrean waiting dan nomor terakhir
        $sqlSummary = "SELECT prefix,
                              service_name,
                              COUNT(CASE WHEN status = 'waiting' THEN 1 END) AS waiting_count,
                              COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed_count,
                              MAX(CASE WHEN status IN ('called', 'serving') THEN ticket_number END) AS current_number
                       FROM queue_tickets
                       WHERE tenant_id = ? AND ticket_date = ?
                       GROUP BY prefix, service_name
                       ORDER BY prefix ASC";
        $summaryRows = $this->db->selectAll($sqlSummary, [$tenantId, $date]);
        $servicesSummary = array_map(function ($s) {
            $s['waiting_count'] = (int) ($s['waiting_count'] ?? 0);
            $s['completed_count'] = (int) ($s['completed_count'] ?? 0);
            return $s;
        }, $summaryRows);

        return [
            'date' => $date,
            'latest_called' => $latestCalled,
            'called_tickets' => $calledTickets,
            'services_summary' => $servicesSummary,
        ];
    }

    public function listWaitingTickets(string $prefix, ?string $date = null): array
    {
        $tenantId = $this->getTenantId();
        $date = $date ?? date('Y-m-d');
        $prefix = strtoupper($prefix);

        $sql = "SELECT q.*,
                       r.name AS room_name,
                       r.code AS room_code,
                       s.name AS space_name
                FROM queue_tickets q
                LEFT JOIN facilities_rooms r ON r.id = q.room_id
                LEFT JOIN facilities_spaces s ON s.id = q.space_id
                WHERE q.tenant_id = ? AND q.ticket_date = ? AND q.prefix = ? AND q.status = 'waiting'
                ORDER BY q.sequence_number ASC";

        $rows = $this->db->selectAll($sql, [$tenantId, $date, $prefix]);
        return array_map([$this, 'castTicketRow'], $rows);
    }

    public function getQueueMetrics(?string $date = null): array
    {
        $tenantId = $this->getTenantId();
        $date = $date ?? date('Y-m-d');

        $sql = "SELECT COUNT(*) AS total_tickets,
                       COUNT(CASE WHEN status = 'waiting' THEN 1 END) AS total_waiting,
                       COUNT(CASE WHEN status = 'called' THEN 1 END) AS total_called,
                       COUNT(CASE WHEN status = 'serving' THEN 1 END) AS total_serving,
                       COUNT(CASE WHEN status = 'completed' THEN 1 END) AS total_completed,
                       COUNT(CASE WHEN status = 'skipped' THEN 1 END) AS total_skipped,
                       COUNT(CASE WHEN status = 'transferred' THEN 1 END) AS total_transferred
                FROM queue_tickets
                WHERE tenant_id = ? AND ticket_date = ?";

        $row = $this->db->selectOne($sql, [$tenantId, $date]);
        return [
            'date' => $date,
            'total_tickets' => (int) ($row['total_tickets'] ?? 0),
            'total_waiting' => (int) ($row['total_waiting'] ?? 0),
            'total_called' => (int) ($row['total_called'] ?? 0),
            'total_serving' => (int) ($row['total_serving'] ?? 0),
            'total_completed' => (int) ($row['total_completed'] ?? 0),
            'total_skipped' => (int) ($row['total_skipped'] ?? 0),
            'total_transferred' => (int) ($row['total_transferred'] ?? 0),
        ];
    }

    private function castTicketRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['tenant_id'] = (int) $row['tenant_id'];
        $row['sequence_number'] = (int) $row['sequence_number'];
        $row['room_id'] = !empty($row['room_id']) ? (int) $row['room_id'] : null;
        $row['space_id'] = !empty($row['space_id']) ? (int) $row['space_id'] : null;
        $row['client_contact_id'] = !empty($row['client_contact_id']) ? (int) $row['client_contact_id'] : null;
        $row['appointment_id'] = !empty($row['appointment_id']) ? (int) $row['appointment_id'] : null;
        $row['called_by'] = !empty($row['called_by']) ? (int) $row['called_by'] : null;
        $row['call_count'] = (int) ($row['call_count'] ?? 0);
        return $row;
    }
}
