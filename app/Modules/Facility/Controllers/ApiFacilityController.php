<?php

declare(strict_types=1);

namespace App\Modules\Facility\Controllers;

use App\Modules\Facility\Contracts\FacilityRegistryInterface;
use Core\Http\Request;
use Core\Http\Response;
use Throwable;

class ApiFacilityController
{
    public function __construct(
        private FacilityRegistryInterface $facilityService
    ) {}

    public function summary(): Response
    {
        return Response::json([
            'status' => 'success',
            'data'   => $this->facilityService->getFacilitySummary(),
        ]);
    }

    // ==========================================
    // Location Endpoints
    // ==========================================

    public function locations(Request $request): Response
    {
        $filters = [
            'type'      => $request->query('type') ? (string) $request->query('type') : null,
            'is_active' => $request->query('is_active') !== null ? (int) $request->query('is_active') : null,
            'search'    => $request->query('q') ? (string) $request->query('q') : null,
        ];

        $locations = $this->facilityService->listLocations(array_filter($filters, fn($v) => $v !== null));

        return Response::json([
            'status' => 'success',
            'data'   => $locations,
        ]);
    }

    public function showLocation(int $id): Response
    {
        $location = $this->facilityService->getLocation($id);
        if (!$location) {
            return Response::json(['status' => 'error', 'message' => 'Location not found.'], 404);
        }

        return Response::json([
            'status' => 'success',
            'data'   => $location,
        ]);
    }

    public function storeLocation(Request $request): Response
    {
        try {
            $data = $request->json();
            if (empty($data)) {
                $data = $request->all();
            }

            $location = $this->facilityService->createLocation($data);
            return Response::json([
                'status'  => 'success',
                'message' => 'Location created successfully.',
                'data'    => $location,
            ], 201);
        } catch (Throwable $e) {
            return Response::json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function updateLocation(int $id, Request $request): Response
    {
        try {
            $data = $request->json();
            if (empty($data)) {
                $data = $request->all();
            }

            $location = $this->facilityService->updateLocation($id, $data);
            return Response::json([
                'status'  => 'success',
                'message' => 'Location updated successfully.',
                'data'    => $location,
            ]);
        } catch (Throwable $e) {
            return Response::json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function deleteLocation(int $id): Response
    {
        try {
            $deleted = $this->facilityService->deleteLocation($id);
            if (!$deleted) {
                return Response::json(['status' => 'error', 'message' => 'Location not found or cannot be deleted.'], 404);
            }

            return Response::json([
                'status'  => 'success',
                'message' => 'Location deleted successfully.',
            ]);
        } catch (Throwable $e) {
            return Response::json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    // ==========================================
    // Room Endpoints
    // ==========================================

    public function rooms(Request $request): Response
    {
        $filters = [
            'location_id' => $request->query('location_id') ? (int) $request->query('location_id') : null,
            'type'        => $request->query('type') ? (string) $request->query('type') : null,
            'is_active'   => $request->query('is_active') !== null ? (int) $request->query('is_active') : null,
            'floor'       => $request->query('floor') ? (string) $request->query('floor') : null,
            'search'      => $request->query('q') ? (string) $request->query('q') : null,
        ];

        $rooms = $this->facilityService->listRooms(array_filter($filters, fn($v) => $v !== null));

        return Response::json([
            'status' => 'success',
            'data'   => $rooms,
        ]);
    }

    public function storageRooms(Request $request): Response
    {
        $locationId = $request->query('location_id') ? (int) $request->query('location_id') : null;
        $rooms = $this->facilityService->listStorageRooms($locationId);

        return Response::json([
            'status' => 'success',
            'data'   => $rooms,
        ]);
    }

    public function showRoom(int $id): Response
    {
        $room = $this->facilityService->getRoom($id);
        if (!$room) {
            return Response::json(['status' => 'error', 'message' => 'Room not found.'], 404);
        }

        return Response::json([
            'status' => 'success',
            'data'   => $room,
        ]);
    }

    public function roomOccupancy(int $id): Response
    {
        try {
            $occupancy = $this->facilityService->getRoomOccupancy($id);
            return Response::json([
                'status' => 'success',
                'data'   => $occupancy,
            ]);
        } catch (Throwable $e) {
            return Response::json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 404);
        }
    }

    public function storeRoom(Request $request): Response
    {
        try {
            $data = $request->json();
            if (empty($data)) {
                $data = $request->all();
            }

            $room = $this->facilityService->createRoom($data);
            return Response::json([
                'status'  => 'success',
                'message' => 'Room created successfully.',
                'data'    => $room,
            ], 201);
        } catch (Throwable $e) {
            return Response::json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function updateRoom(int $id, Request $request): Response
    {
        try {
            $data = $request->json();
            if (empty($data)) {
                $data = $request->all();
            }

            $room = $this->facilityService->updateRoom($id, $data);
            return Response::json([
                'status'  => 'success',
                'message' => 'Room updated successfully.',
                'data'    => $room,
            ]);
        } catch (Throwable $e) {
            return Response::json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function deleteRoom(int $id): Response
    {
        try {
            $deleted = $this->facilityService->deleteRoom($id);
            if (!$deleted) {
                return Response::json(['status' => 'error', 'message' => 'Room not found or cannot be deleted.'], 404);
            }

            return Response::json([
                'status'  => 'success',
                'message' => 'Room deleted successfully.',
            ]);
        } catch (Throwable $e) {
            return Response::json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    // ==========================================
    // Space Endpoints
    // ==========================================

    public function spaces(Request $request): Response
    {
        $filters = [
            'room_id'   => $request->query('room_id') ? (int) $request->query('room_id') : null,
            'type'      => $request->query('type') ? (string) $request->query('type') : null,
            'status'    => $request->query('status') ? (string) $request->query('status') : null,
            'is_active' => $request->query('is_active') !== null ? (int) $request->query('is_active') : null,
            'search'    => $request->query('q') ? (string) $request->query('q') : null,
        ];

        $spaces = $this->facilityService->listSpaces(array_filter($filters, fn($v) => $v !== null));

        return Response::json([
            'status' => 'success',
            'data'   => $spaces,
        ]);
    }

    public function showSpace(int $id): Response
    {
        $space = $this->facilityService->getSpace($id);
        if (!$space) {
            return Response::json(['status' => 'error', 'message' => 'Space not found.'], 404);
        }

        return Response::json([
            'status' => 'success',
            'data'   => $space,
        ]);
    }

    public function storeSpace(Request $request): Response
    {
        try {
            $data = $request->json();
            if (empty($data)) {
                $data = $request->all();
            }

            $space = $this->facilityService->createSpace($data);
            return Response::json([
                'status'  => 'success',
                'message' => 'Space created successfully.',
                'data'    => $space,
            ], 201);
        } catch (Throwable $e) {
            return Response::json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function updateSpaceStatus(int $id, Request $request): Response
    {
        try {
            $data = $request->json();
            $status = (string) ($data['status'] ?? $request->input('status', 'available'));

            $space = $this->facilityService->updateSpaceStatus($id, $status);
            return Response::json([
                'status'  => 'success',
                'message' => 'Space status updated successfully.',
                'data'    => $space,
            ]);
        } catch (Throwable $e) {
            return Response::json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function deleteSpace(int $id): Response
    {
        try {
            $deleted = $this->facilityService->deleteSpace($id);
            if (!$deleted) {
                return Response::json(['status' => 'error', 'message' => 'Space not found or cannot be deleted.'], 404);
            }

            return Response::json([
                'status'  => 'success',
                'message' => 'Space deleted successfully.',
            ]);
        } catch (Throwable $e) {
            return Response::json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
