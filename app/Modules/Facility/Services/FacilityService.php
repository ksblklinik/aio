<?php

declare(strict_types=1);

namespace App\Modules\Facility\Services;

use App\Modules\Facility\Contracts\FacilityRegistryInterface;
use Core\Database\Connection;
use Core\Database\TenantContext;
use InvalidArgumentException;
use RuntimeException;

class FacilityService implements FacilityRegistryInterface
{
    private const VALID_LOCATION_TYPES = [
        'headquarters',
        'branch',
        'site',
        'warehouse',
        'outpost',
        'other',
    ];

    private const VALID_ROOM_TYPES = [
        'service',
        'storage',
        'inpatient',
        'office',
        'meeting',
        'procedure',
        'other',
    ];

    private const VALID_SPACE_TYPES = [
        'bed',
        'desk',
        'chair',
        'dock',
        'booth',
        'other',
    ];

    private const VALID_SPACE_STATUSES = [
        'available',
        'occupied',
        'maintenance',
        'reserved',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly TenantContext $tenantContext
    ) {}

    private function getTenantId(): int
    {
        $id = $this->tenantContext->getTenantId();
        if ($id === null || $id === '') {
            throw new RuntimeException('Tenant context is required for Facility operations.');
        }
        return (int) $id;
    }

    // ==========================================
    // Location Management
    // ==========================================

    public function createLocation(array $data): array
    {
        $tenantId = $this->getTenantId();

        $name = trim($data['name'] ?? '');
        if ($name === '') {
            throw new InvalidArgumentException('Location name is required.');
        }

        $type = $data['type'] ?? 'headquarters';
        if (!in_array($type, self::VALID_LOCATION_TYPES, true)) {
            $type = 'other';
        }

        $code = trim($data['code'] ?? '');
        if ($code === '') {
            $code = sequence('facility_location', 'LOC', 5, 'yearly', null, $tenantId);
        }

        $id = (int) $this->db->insert('facilities_locations', [
            'tenant_id'  => $tenantId,
            'code'       => $code,
            'name'       => $name,
            'type'       => $type,
            'address'    => $data['address'] ?? null,
            'city'       => $data['city'] ?? null,
            'phone'      => $data['phone'] ?? null,
            'is_active'  => isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->getLocation($id) ?? throw new RuntimeException('Failed to retrieve newly created location.');
    }

    public function updateLocation(int $id, array $data): array
    {
        $tenantId = $this->getTenantId();
        $existing = $this->getLocation($id);
        if (!$existing) {
            throw new RuntimeException("Location with ID {$id} not found.");
        }

        $updates = [];
        if (isset($data['name'])) {
            $name = trim((string)$data['name']);
            if ($name === '') {
                throw new InvalidArgumentException('Location name cannot be empty.');
            }
            $updates['name'] = $name;
        }

        if (isset($data['type'])) {
            $type = (string)$data['type'];
            if (!in_array($type, self::VALID_LOCATION_TYPES, true)) {
                throw new InvalidArgumentException("Invalid location type: {$type}");
            }
            $updates['type'] = $type;
        }

        if (array_key_exists('address', $data)) {
            $updates['address'] = $data['address'];
        }
        if (array_key_exists('city', $data)) {
            $updates['city'] = $data['city'];
        }
        if (array_key_exists('phone', $data)) {
            $updates['phone'] = $data['phone'];
        }
        if (isset($data['is_active'])) {
            $updates['is_active'] = (int)(bool)$data['is_active'];
        }

        if (!empty($updates)) {
            $updates['updated_at'] = date('Y-m-d H:i:s');
            $this->db->update('facilities_locations', $updates, 'id = ? AND tenant_id = ?', [$id, $tenantId]);
        }

        return $this->getLocation($id) ?? throw new RuntimeException('Failed to retrieve updated location.');
    }

    public function getLocation(int $id): ?array
    {
        $tenantId = $this->getTenantId();
        $row = $this->db->selectOne(
            "SELECT l.*,
                    (SELECT COUNT(*) FROM facilities_rooms r WHERE r.location_id = l.id) AS rooms_count
             FROM facilities_locations l
             WHERE l.id = ? AND l.tenant_id = ?",
            [$id, $tenantId]
        );

        if (!$row) {
            return null;
        }

        $row['id'] = (int)$row['id'];
        $row['tenant_id'] = (int)$row['tenant_id'];
        $row['is_active'] = (bool)$row['is_active'];
        $row['rooms_count'] = (int)($row['rooms_count'] ?? 0);

        return $row;
    }

    public function listLocations(array $filters = []): array
    {
        $tenantId = $this->getTenantId();
        $sql = "SELECT l.*,
                       (SELECT COUNT(*) FROM facilities_rooms r WHERE r.location_id = l.id) AS rooms_count
                FROM facilities_locations l
                WHERE l.tenant_id = ?";
        $params = [$tenantId];

        if (!empty($filters['type'])) {
            $sql .= " AND l.type = ?";
            $params[] = $filters['type'];
        }

        if (isset($filters['is_active'])) {
            $sql .= " AND l.is_active = ?";
            $params[] = (int)(bool)$filters['is_active'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (l.name LIKE ? OR l.code LIKE ? OR l.city LIKE ?)";
            $term = '%' . $filters['search'] . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        $sql .= " ORDER BY l.name ASC";

        $rows = $this->db->selectAll($sql, $params);
        return array_map(function ($row) {
            $row['id'] = (int)$row['id'];
            $row['tenant_id'] = (int)$row['tenant_id'];
            $row['is_active'] = (bool)$row['is_active'];
            $row['rooms_count'] = (int)($row['rooms_count'] ?? 0);
            return $row;
        }, $rows);
    }

    public function deleteLocation(int $id): bool
    {
        $tenantId = $this->getTenantId();
        $existing = $this->getLocation($id);
        if (!$existing) {
            return false;
        }

        $roomsCount = (int) ($this->db->selectOne(
            "SELECT COUNT(*) as count FROM facilities_rooms WHERE location_id = ? AND tenant_id = ?",
            [$id, $tenantId]
        )['count'] ?? 0);

        if ($roomsCount > 0) {
            throw new RuntimeException('Cannot delete location with existing rooms.');
        }

        $deleted = $this->db->delete('facilities_locations', 'id = ? AND tenant_id = ?', [$id, $tenantId]);
        return $deleted > 0;
    }

    // ==========================================
    // Room Management
    // ==========================================

    public function createRoom(array $data): array
    {
        $tenantId = $this->getTenantId();

        $locationId = (int)($data['location_id'] ?? 0);
        if ($locationId <= 0) {
            throw new InvalidArgumentException('Valid location_id is required.');
        }

        $loc = $this->getLocation($locationId);
        if (!$loc) {
            throw new InvalidArgumentException("Location ID {$locationId} does not belong to active tenant.");
        }

        $name = trim($data['name'] ?? '');
        if ($name === '') {
            throw new InvalidArgumentException('Room name is required.');
        }

        $type = $data['type'] ?? 'service';
        if (!in_array($type, self::VALID_ROOM_TYPES, true)) {
            $type = 'other';
        }

        $code = trim($data['code'] ?? '');
        if ($code === '') {
            $code = sequence('facility_room', 'RM', 5, 'yearly', null, $tenantId);
        }

        $floor = trim((string)($data['floor'] ?? '1'));
        if ($floor === '') {
            $floor = '1';
        }

        $capacity = (int)($data['capacity'] ?? 1);
        if ($capacity < 1) {
            $capacity = 1;
        }

        $id = (int) $this->db->insert('facilities_rooms', [
            'tenant_id'   => $tenantId,
            'location_id' => $locationId,
            'code'        => $code,
            'name'        => $name,
            'floor'       => $floor,
            'type'        => $type,
            'capacity'    => $capacity,
            'notes'       => $data['notes'] ?? null,
            'is_active'   => isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        return $this->getRoom($id) ?? throw new RuntimeException('Failed to retrieve newly created room.');
    }

    public function updateRoom(int $id, array $data): array
    {
        $tenantId = $this->getTenantId();
        $existing = $this->getRoom($id);
        if (!$existing) {
            throw new RuntimeException("Room with ID {$id} not found.");
        }

        $updates = [];

        if (isset($data['location_id'])) {
            $newLocId = (int)$data['location_id'];
            $loc = $this->getLocation($newLocId);
            if (!$loc) {
                throw new InvalidArgumentException("Location ID {$newLocId} does not exist for active tenant.");
            }
            $updates['location_id'] = $newLocId;
        }

        if (isset($data['name'])) {
            $name = trim((string)$data['name']);
            if ($name === '') {
                throw new InvalidArgumentException('Room name cannot be empty.');
            }
            $updates['name'] = $name;
        }

        if (isset($data['type'])) {
            $type = (string)$data['type'];
            if (!in_array($type, self::VALID_ROOM_TYPES, true)) {
                throw new InvalidArgumentException("Invalid room type: {$type}");
            }
            $updates['type'] = $type;
        }

        if (isset($data['floor'])) {
            $updates['floor'] = trim((string)$data['floor']);
        }

        if (isset($data['capacity'])) {
            $cap = (int)$data['capacity'];
            $updates['capacity'] = $cap > 0 ? $cap : 1;
        }

        if (array_key_exists('notes', $data)) {
            $updates['notes'] = $data['notes'];
        }

        if (isset($data['is_active'])) {
            $updates['is_active'] = (int)(bool)$data['is_active'];
        }

        if (!empty($updates)) {
            $updates['updated_at'] = date('Y-m-d H:i:s');
            $this->db->update('facilities_rooms', $updates, 'id = ? AND tenant_id = ?', [$id, $tenantId]);
        }

        return $this->getRoom($id) ?? throw new RuntimeException('Failed to retrieve updated room.');
    }

    public function getRoom(int $id): ?array
    {
        $tenantId = $this->getTenantId();
        $row = $this->db->selectOne(
            "SELECT r.*,
                    l.name AS location_name,
                    l.code AS location_code,
                    (SELECT COUNT(*) FROM facilities_spaces s WHERE s.room_id = r.id) AS spaces_count
             FROM facilities_rooms r
             JOIN facilities_locations l ON l.id = r.location_id
             WHERE r.id = ? AND r.tenant_id = ?",
            [$id, $tenantId]
        );

        if (!$row) {
            return null;
        }

        $row['id'] = (int)$row['id'];
        $row['tenant_id'] = (int)$row['tenant_id'];
        $row['location_id'] = (int)$row['location_id'];
        $row['capacity'] = (int)$row['capacity'];
        $row['is_active'] = (bool)$row['is_active'];
        $row['spaces_count'] = (int)($row['spaces_count'] ?? 0);

        return $row;
    }

    public function listRooms(array $filters = []): array
    {
        $tenantId = $this->getTenantId();
        $sql = "SELECT r.*,
                       l.name AS location_name,
                       l.code AS location_code,
                       (SELECT COUNT(*) FROM facilities_spaces s WHERE s.room_id = r.id) AS spaces_count
                FROM facilities_rooms r
                JOIN facilities_locations l ON l.id = r.location_id
                WHERE r.tenant_id = ?";
        $params = [$tenantId];

        if (!empty($filters['location_id'])) {
            $sql .= " AND r.location_id = ?";
            $params[] = (int)$filters['location_id'];
        }

        if (!empty($filters['type'])) {
            $sql .= " AND r.type = ?";
            $params[] = $filters['type'];
        }

        if (isset($filters['is_active'])) {
            $sql .= " AND r.is_active = ?";
            $params[] = (int)(bool)$filters['is_active'];
        }

        if (!empty($filters['floor'])) {
            $sql .= " AND r.floor = ?";
            $params[] = (string)$filters['floor'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (r.name LIKE ? OR r.code LIKE ?)";
            $term = '%' . $filters['search'] . '%';
            $params[] = $term;
            $params[] = $term;
        }

        $sql .= " ORDER BY r.floor ASC, r.name ASC";

        $rows = $this->db->selectAll($sql, $params);
        return array_map(function ($row) {
            $row['id'] = (int)$row['id'];
            $row['tenant_id'] = (int)$row['tenant_id'];
            $row['location_id'] = (int)$row['location_id'];
            $row['capacity'] = (int)$row['capacity'];
            $row['is_active'] = (bool)$row['is_active'];
            $row['spaces_count'] = (int)($row['spaces_count'] ?? 0);
            return $row;
        }, $rows);
    }

    public function listStorageRooms(?int $locationId = null): array
    {
        $filters = [
            'type'      => 'storage',
            'is_active' => true,
        ];

        if ($locationId !== null) {
            $filters['location_id'] = $locationId;
        }

        return $this->listRooms($filters);
    }

    public function deleteRoom(int $id): bool
    {
        $tenantId = $this->getTenantId();
        $existing = $this->getRoom($id);
        if (!$existing) {
            return false;
        }

        $spacesCount = (int) ($this->db->selectOne(
            "SELECT COUNT(*) as count FROM facilities_spaces WHERE room_id = ? AND tenant_id = ?",
            [$id, $tenantId]
        )['count'] ?? 0);

        if ($spacesCount > 0) {
            throw new RuntimeException('Cannot delete room with existing spaces.');
        }

        $deleted = $this->db->delete('facilities_rooms', 'id = ? AND tenant_id = ?', [$id, $tenantId]);
        return $deleted > 0;
    }

    // ==========================================
    // Space / Slot Management
    // ==========================================

    public function createSpace(array $data): array
    {
        $tenantId = $this->getTenantId();

        $roomId = (int)($data['room_id'] ?? 0);
        if ($roomId <= 0) {
            throw new InvalidArgumentException('Valid room_id is required.');
        }

        $room = $this->getRoom($roomId);
        if (!$room) {
            throw new InvalidArgumentException("Room ID {$roomId} does not belong to active tenant.");
        }

        $name = trim($data['name'] ?? '');
        if ($name === '') {
            throw new InvalidArgumentException('Space name is required.');
        }

        $type = $data['type'] ?? 'bed';
        if (!in_array($type, self::VALID_SPACE_TYPES, true)) {
            $type = 'other';
        }

        $status = $data['status'] ?? 'available';
        if (!in_array($status, self::VALID_SPACE_STATUSES, true)) {
            throw new InvalidArgumentException("Invalid space status: {$status}");
        }

        $code = trim($data['code'] ?? '');
        if ($code === '') {
            $code = sequence('facility_space', 'SPC', 5, 'yearly', null, $tenantId);
        }

        $id = (int) $this->db->insert('facilities_spaces', [
            'tenant_id'  => $tenantId,
            'room_id'    => $roomId,
            'code'       => $code,
            'name'       => $name,
            'type'       => $type,
            'status'     => $status,
            'is_active'  => isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->getSpace($id) ?? throw new RuntimeException('Failed to retrieve newly created space.');
    }

    public function updateSpace(int $id, array $data): array
    {
        $tenantId = $this->getTenantId();
        $existing = $this->getSpace($id);
        if (!$existing) {
            throw new RuntimeException("Space with ID {$id} not found.");
        }

        $updates = [];

        if (isset($data['name'])) {
            $name = trim((string)$data['name']);
            if ($name === '') {
                throw new InvalidArgumentException('Space name cannot be empty.');
            }
            $updates['name'] = $name;
        }

        if (isset($data['type'])) {
            $type = (string)$data['type'];
            if (!in_array($type, self::VALID_SPACE_TYPES, true)) {
                throw new InvalidArgumentException("Invalid space type: {$type}");
            }
            $updates['type'] = $type;
        }

        if (isset($data['status'])) {
            $status = (string)$data['status'];
            if (!in_array($status, self::VALID_SPACE_STATUSES, true)) {
                throw new InvalidArgumentException("Invalid space status: {$status}");
            }
            $updates['status'] = $status;
        }

        if (isset($data['is_active'])) {
            $updates['is_active'] = (int)(bool)$data['is_active'];
        }

        if (!empty($updates)) {
            $updates['updated_at'] = date('Y-m-d H:i:s');
            $this->db->update('facilities_spaces', $updates, 'id = ? AND tenant_id = ?', [$id, $tenantId]);
        }

        return $this->getSpace($id) ?? throw new RuntimeException('Failed to retrieve updated space.');
    }

    public function getSpace(int $id): ?array
    {
        $tenantId = $this->getTenantId();
        $row = $this->db->selectOne(
            "SELECT s.*,
                    r.name AS room_name,
                    r.code AS room_code,
                    r.floor AS room_floor,
                    l.id AS location_id,
                    l.name AS location_name
             FROM facilities_spaces s
             JOIN facilities_rooms r ON r.id = s.room_id
             JOIN facilities_locations l ON l.id = r.location_id
             WHERE s.id = ? AND s.tenant_id = ?",
            [$id, $tenantId]
        );

        if (!$row) {
            return null;
        }

        $row['id'] = (int)$row['id'];
        $row['tenant_id'] = (int)$row['tenant_id'];
        $row['room_id'] = (int)$row['room_id'];
        $row['location_id'] = (int)$row['location_id'];
        $row['is_active'] = (bool)$row['is_active'];

        return $row;
    }

    public function listSpaces(array $filters = []): array
    {
        $tenantId = $this->getTenantId();
        $sql = "SELECT s.*,
                       r.name AS room_name,
                       r.code AS room_code,
                       r.floor AS room_floor,
                       l.id AS location_id,
                       l.name AS location_name
                FROM facilities_spaces s
                JOIN facilities_rooms r ON r.id = s.room_id
                JOIN facilities_locations l ON l.id = r.location_id
                WHERE s.tenant_id = ?";
        $params = [$tenantId];

        if (!empty($filters['room_id'])) {
            $sql .= " AND s.room_id = ?";
            $params[] = (int)$filters['room_id'];
        }

        if (!empty($filters['type'])) {
            $sql .= " AND s.type = ?";
            $params[] = $filters['type'];
        }

        if (!empty($filters['status'])) {
            $sql .= " AND s.status = ?";
            $params[] = $filters['status'];
        }

        if (isset($filters['is_active'])) {
            $sql .= " AND s.is_active = ?";
            $params[] = (int)(bool)$filters['is_active'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (s.name LIKE ? OR s.code LIKE ?)";
            $term = '%' . $filters['search'] . '%';
            $params[] = $term;
            $params[] = $term;
        }

        $sql .= " ORDER BY s.code ASC";

        $rows = $this->db->selectAll($sql, $params);
        return array_map(function ($row) {
            $row['id'] = (int)$row['id'];
            $row['tenant_id'] = (int)$row['tenant_id'];
            $row['room_id'] = (int)$row['room_id'];
            $row['location_id'] = (int)$row['location_id'];
            $row['is_active'] = (bool)$row['is_active'];
            return $row;
        }, $rows);
    }

    public function updateSpaceStatus(int $id, string $status): array
    {
        if (!in_array($status, self::VALID_SPACE_STATUSES, true)) {
            throw new InvalidArgumentException("Invalid space status: {$status}");
        }

        return $this->updateSpace($id, ['status' => $status]);
    }

    public function deleteSpace(int $id): bool
    {
        $tenantId = $this->getTenantId();
        $existing = $this->getSpace($id);
        if (!$existing) {
            return false;
        }

        $deleted = $this->db->delete('facilities_spaces', 'id = ? AND tenant_id = ?', [$id, $tenantId]);
        return $deleted > 0;
    }

    // ==========================================
    // Availability & Occupancy
    // ==========================================

    public function getRoomOccupancy(int $roomId): array
    {
        $tenantId = $this->getTenantId();
        $room = $this->getRoom($roomId);
        if (!$room) {
            throw new RuntimeException("Room with ID {$roomId} not found.");
        }

        $spaces = $this->db->selectAll(
            "SELECT status, COUNT(*) as cnt
             FROM facilities_spaces
             WHERE room_id = ? AND tenant_id = ? AND is_active = 1
             GROUP BY status",
            [$roomId, $tenantId]
        );

        $counts = [
            'available'   => 0,
            'occupied'    => 0,
            'maintenance' => 0,
            'reserved'    => 0,
        ];

        $totalSpaces = 0;
        foreach ($spaces as $s) {
            $st = $s['status'];
            $cnt = (int)$s['cnt'];
            if (isset($counts[$st])) {
                $counts[$st] = $cnt;
            }
            $totalSpaces += $cnt;
        }

        $occupancyRate = $totalSpaces > 0 ? round(($counts['occupied'] / $totalSpaces) * 100, 2) : 0.0;

        return [
            'room_id'        => $roomId,
            'room_name'      => $room['name'],
            'room_capacity'  => $room['capacity'],
            'total_spaces'   => $totalSpaces,
            'available'      => $counts['available'],
            'occupied'       => $counts['occupied'],
            'maintenance'    => $counts['maintenance'],
            'reserved'       => $counts['reserved'],
            'occupancy_rate' => $occupancyRate,
        ];
    }

    public function getFacilitySummary(): array
    {
        $tenantId = $this->getTenantId();

        $totalLocations = (int) ($this->db->selectOne(
            "SELECT COUNT(*) as count FROM facilities_locations WHERE tenant_id = ? AND is_active = 1",
            [$tenantId]
        )['count'] ?? 0);

        $totalRooms = (int) ($this->db->selectOne(
            "SELECT COUNT(*) as count FROM facilities_rooms WHERE tenant_id = ? AND is_active = 1",
            [$tenantId]
        )['count'] ?? 0);

        $totalStorageRooms = (int) ($this->db->selectOne(
            "SELECT COUNT(*) as count FROM facilities_rooms WHERE tenant_id = ? AND type = 'storage' AND is_active = 1",
            [$tenantId]
        )['count'] ?? 0);

        $spaceStats = $this->db->selectAll(
            "SELECT status, COUNT(*) as cnt
             FROM facilities_spaces
             WHERE tenant_id = ? AND is_active = 1
             GROUP BY status",
            [$tenantId]
        );

        $spacesByStatus = [
            'available'   => 0,
            'occupied'    => 0,
            'maintenance' => 0,
            'reserved'    => 0,
        ];

        $totalSpaces = 0;
        foreach ($spaceStats as $row) {
            $st = $row['status'];
            $cnt = (int)$row['cnt'];
            if (isset($spacesByStatus[$st])) {
                $spacesByStatus[$st] = $cnt;
            }
            $totalSpaces += $cnt;
        }

        return [
            'total_locations'     => $totalLocations,
            'total_rooms'         => $totalRooms,
            'total_storage_rooms' => $totalStorageRooms,
            'total_spaces'        => $totalSpaces,
            'available_spaces'    => $spacesByStatus['available'],
            'occupied_spaces'     => $spacesByStatus['occupied'],
            'maintenance_spaces'  => $spacesByStatus['maintenance'],
            'reserved_spaces'     => $spacesByStatus['reserved'],
        ];
    }
}
