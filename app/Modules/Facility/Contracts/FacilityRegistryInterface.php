<?php

declare(strict_types=1);

namespace App\Modules\Facility\Contracts;

interface FacilityRegistryInterface
{
    // Location Management
    public function createLocation(array $data): array;
    public function updateLocation(int $id, array $data): array;
    public function getLocation(int $id): ?array;
    public function listLocations(array $filters = []): array;
    public function deleteLocation(int $id): bool;

    // Room Management
    public function createRoom(array $data): array;
    public function updateRoom(int $id, array $data): array;
    public function getRoom(int $id): ?array;
    public function listRooms(array $filters = []): array;
    public function listStorageRooms(?int $locationId = null): array;
    public function deleteRoom(int $id): bool;

    // Space / Slot Management
    public function createSpace(array $data): array;
    public function updateSpace(int $id, array $data): array;
    public function getSpace(int $id): ?array;
    public function listSpaces(array $filters = []): array;
    public function updateSpaceStatus(int $id, string $status): array;
    public function deleteSpace(int $id): bool;

    // Availability & Occupancy
    public function getRoomOccupancy(int $roomId): array;
    public function getFacilitySummary(): array;
}
