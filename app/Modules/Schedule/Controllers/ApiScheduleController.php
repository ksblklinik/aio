<?php

declare(strict_types=1);

namespace App\Modules\Schedule\Controllers;

use App\Modules\Schedule\Contracts\ScheduleRegistryInterface;
use Core\Http\Request;
use Core\Http\Response;
use Throwable;

class ApiScheduleController
{
    public function __construct(
        private ScheduleRegistryInterface $scheduleService
    ) {
    }

    public function appointments(Request $request): Response
    {
        $filters = [];
        if ($request->query('date')) {
            $filters['date'] = (string) $request->query('date');
        }
        if ($request->query('status')) {
            $filters['status'] = (string) $request->query('status');
        }
        if ($request->query('staff_contact_id')) {
            $filters['staff_contact_id'] = (int) $request->query('staff_contact_id');
        }
        if ($request->query('room_id')) {
            $filters['room_id'] = (int) $request->query('room_id');
        }

        return Response::json([
            'status' => 'success',
            'data' => $this->scheduleService->listAppointments($filters),
        ]);
    }

    public function storeAppointment(Request $request): Response
    {
        try {
            $data = [
                'client_name' => (string) $request->input('client_name'),
                'client_phone' => $request->input('client_phone') ? (string) $request->input('client_phone') : null,
                'client_email' => $request->input('client_email') ? (string) $request->input('client_email') : null,
                'client_contact_id' => $request->input('client_contact_id') ? (int) $request->input('client_contact_id') : null,
                'staff_contact_id' => $request->input('staff_contact_id') ? (int) $request->input('staff_contact_id') : null,
                'room_id' => $request->input('room_id') ? (int) $request->input('room_id') : null,
                'service_item_id' => $request->input('service_item_id') ? (int) $request->input('service_item_id') : null,
                'appointment_date' => (string) $request->input('appointment_date', date('Y-m-d')),
                'start_time' => (string) $request->input('start_time', '09:00'),
                'end_time' => $request->input('end_time') ? (string) $request->input('end_time') : null,
                'notes' => $request->input('notes') ? (string) $request->input('notes') : null,
            ];

            $appointment = $this->scheduleService->createAppointment($data);

            return Response::json([
                'status' => 'success',
                'message' => 'Appointment created successfully.',
                'data' => $appointment,
            ], 201);
        } catch (Throwable $e) {
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function checkIn(Request $request, int $id): Response
    {
        try {
            $targetPrefix = $request->input('prefix') ? (string) $request->input('prefix') : null;
            $roomId = $request->input('room_id') ? (int) $request->input('room_id') : null;

            $result = $this->scheduleService->checkInAppointment($id, $targetPrefix, $roomId);

            return Response::json([
                'status' => 'success',
                'message' => 'Appointment checked in successfully.',
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function displayState(Request $request): Response
    {
        $date = $request->query('date') ? (string) $request->query('date') : null;
        return Response::json([
            'status' => 'success',
            'data' => $this->scheduleService->getDisplayQueueState($date),
        ]);
    }

    public function waitingTickets(Request $request): Response
    {
        $prefix = (string) $request->query('prefix', 'A');
        $date = $request->query('date') ? (string) $request->query('date') : null;

        return Response::json([
            'status' => 'success',
            'data' => $this->scheduleService->listWaitingTickets($prefix, $date),
        ]);
    }

    public function metrics(Request $request): Response
    {
        $date = $request->query('date') ? (string) $request->query('date') : null;

        return Response::json([
            'status' => 'success',
            'data' => $this->scheduleService->getQueueMetrics($date),
        ]);
    }

    public function issueTicket(Request $request): Response
    {
        try {
            $data = [
                'prefix' => (string) $request->input('prefix', 'A'),
                'service_name' => (string) $request->input('service_name', 'Poli Umum'),
                'room_id' => $request->input('room_id') ? (int) $request->input('room_id') : null,
                'space_id' => $request->input('space_id') ? (int) $request->input('space_id') : null,
                'client_contact_id' => $request->input('client_contact_id') ? (int) $request->input('client_contact_id') : null,
                'client_name' => (string) $request->input('client_name', 'Pengunjung Umum'),
                'notes' => $request->input('notes') ? (string) $request->input('notes') : null,
            ];

            $ticket = $this->scheduleService->issueQueueTicket($data);

            return Response::json([
                'status' => 'success',
                'message' => 'Queue ticket issued successfully.',
                'data' => $ticket,
            ], 201);
        } catch (Throwable $e) {
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function callNext(Request $request): Response
    {
        try {
            $prefix = (string) $request->input('prefix', 'A');
            $roomId = $request->input('room_id') ? (int) $request->input('room_id') : null;
            $spaceId = $request->input('space_id') ? (int) $request->input('space_id') : null;
            $userId = $request->input('user_id') ? (int) $request->input('user_id') : null;

            $called = $this->scheduleService->callNextTicket($prefix, $roomId, $spaceId, $userId);

            if (!$called) {
                return Response::json([
                    'status' => 'success',
                    'message' => 'No waiting tickets in queue.',
                    'data' => null,
                ]);
            }

            return Response::json([
                'status' => 'success',
                'message' => 'Ticket called successfully.',
                'data' => $called,
            ]);
        } catch (Throwable $e) {
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function recall(int $id): Response
    {
        try {
            $ticket = $this->scheduleService->recallTicket($id);
            return Response::json([
                'status' => 'success',
                'message' => 'Ticket recalled successfully.',
                'data' => $ticket,
            ]);
        } catch (Throwable $e) {
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function serving(int $id): Response
    {
        try {
            $ticket = $this->scheduleService->startServing($id);
            return Response::json([
                'status' => 'success',
                'message' => 'Serving started.',
                'data' => $ticket,
            ]);
        } catch (Throwable $e) {
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function complete(Request $request, int $id): Response
    {
        try {
            $notes = $request->input('notes') ? (string) $request->input('notes') : null;
            $ticket = $this->scheduleService->completeTicket($id, $notes);

            return Response::json([
                'status' => 'success',
                'message' => 'Ticket completed.',
                'data' => $ticket,
            ]);
        } catch (Throwable $e) {
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function skip(Request $request, int $id): Response
    {
        try {
            $reason = $request->input('reason') ? (string) $request->input('reason') : null;
            $ticket = $this->scheduleService->skipTicket($id, $reason);

            return Response::json([
                'status' => 'success',
                'message' => 'Ticket marked as skipped.',
                'data' => $ticket,
            ]);
        } catch (Throwable $e) {
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function transfer(Request $request, int $id): Response
    {
        try {
            $targetPrefix = (string) $request->input('target_prefix', 'B');
            $targetRoomId = $request->input('target_room_id') ? (int) $request->input('target_room_id') : null;
            $notes = $request->input('notes') ? (string) $request->input('notes') : null;

            $newTicket = $this->scheduleService->transferTicket($id, $targetPrefix, $targetRoomId, $notes);

            return Response::json([
                'status' => 'success',
                'message' => 'Ticket transferred successfully.',
                'data' => $newTicket,
            ], 201);
        } catch (Throwable $e) {
            return Response::json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }
}
