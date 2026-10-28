<?php

declare(strict_types=1);

namespace App\Modules\Facility\Controllers;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Facility\Contracts\FacilityRegistryInterface;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;
use Core\Inertia\Inertia;
use Throwable;

class FacilityController
{
    public function __construct(
        private AuthService $auth,
        private FacilityRegistryInterface $facilityService,
        private Session $session,
        private ?Inertia $inertia = null
    ) {}

    public function index(Request $request): Response
    {
        $tenant = $this->auth->currentTenant();

        $selectedLocationId = $request->query('location_id') ? (int) $request->query('location_id') : null;
        $roomType = $request->query('room_type') ? (string) $request->query('room_type') : null;

        $locations = $this->facilityService->listLocations();
        $roomFilters = [];
        if ($selectedLocationId !== null) {
            $roomFilters['location_id'] = $selectedLocationId;
        }
        if ($roomType !== null) {
            $roomFilters['type'] = $roomType;
        }

        $rooms = $this->facilityService->listRooms($roomFilters);
        $spaces = $this->facilityService->listSpaces();
        $summary = $this->facilityService->getFacilitySummary();

        if ($this->inertia) {
            return $this->inertia->render('Facility/Index', [
                'locations'          => $locations,
                'rooms'              => $rooms,
                'spaces'             => $spaces,
                'summary'            => $summary,
                'selectedLocationId' => $selectedLocationId,
                'currentTenant'      => $tenant ? [
                    'id'   => $tenant->id,
                    'name' => $tenant->name,
                ] : null,
            ]);
        }

        return Response::json([
            'status'    => 'success',
            'summary'   => $summary,
            'locations' => $locations,
            'rooms'     => $rooms,
            'spaces'    => $spaces,
        ]);
    }

    public function storeLocation(Request $request): Response
    {
        try {
            $data = $request->all();
            $location = $this->facilityService->createLocation($data);
            $this->session->flash('success', "Lokasi '{$location['name']}' berhasil dibuat.");
            return Response::redirect('/facility');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/facility');
        }
    }

    public function updateLocation(int $id, Request $request): Response
    {
        try {
            $data = $request->all();
            $location = $this->facilityService->updateLocation($id, $data);
            $this->session->flash('success', "Lokasi '{$location['name']}' berhasil diperbarui.");
            return Response::redirect('/facility');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/facility');
        }
    }

    public function deleteLocation(int $id): Response
    {
        try {
            $this->facilityService->deleteLocation($id);
            $this->session->flash('success', 'Lokasi berhasil dihapus.');
            return Response::redirect('/facility');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/facility');
        }
    }

    public function storeRoom(Request $request): Response
    {
        try {
            $data = $request->all();
            $room = $this->facilityService->createRoom($data);
            $this->session->flash('success', "Ruangan '{$room['name']}' berhasil ditambahkan.");
            return Response::redirect('/facility');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/facility');
        }
    }

    public function updateRoom(int $id, Request $request): Response
    {
        try {
            $data = $request->all();
            $room = $this->facilityService->updateRoom($id, $data);
            $this->session->flash('success', "Ruangan '{$room['name']}' berhasil diperbarui.");
            return Response::redirect('/facility');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/facility');
        }
    }

    public function deleteRoom(int $id): Response
    {
        try {
            $this->facilityService->deleteRoom($id);
            $this->session->flash('success', 'Ruangan berhasil dihapus.');
            return Response::redirect('/facility');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/facility');
        }
    }

    public function storeSpace(Request $request): Response
    {
        try {
            $data = $request->all();
            $space = $this->facilityService->createSpace($data);
            $this->session->flash('success', "Unit '{$space['name']}' berhasil ditambahkan.");
            return Response::redirect('/facility');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/facility');
        }
    }

    public function updateSpaceStatus(int $id, Request $request): Response
    {
        try {
            $status = (string) $request->input('status', 'available');
            $space = $this->facilityService->updateSpaceStatus($id, $status);
            $this->session->flash('success', "Status unit '{$space['name']}' diperbarui ke {$status}.");
            return Response::redirect('/facility');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/facility');
        }
    }

    public function deleteSpace(int $id): Response
    {
        try {
            $this->facilityService->deleteSpace($id);
            $this->session->flash('success', 'Unit ruang berhasil dihapus.');
            return Response::redirect('/facility');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/facility');
        }
    }
}
