<?php

declare(strict_types=1);

namespace App\Modules\Schedule\Controllers;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Schedule\Contracts\ScheduleRegistryInterface;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Session;
use Core\Inertia\Inertia;
use Throwable;

class ScheduleController
{
    public function __construct(
        private AuthService $auth,
        private ScheduleRegistryInterface $scheduleService,
        private Session $session,
        private ?Inertia $inertia = null
    ) {
    }

    public function index(Request $request): Response
    {
        $tenant = $this->auth->currentTenant();
        $date = (string) $request->query('date', date('Y-m-d'));

        $schedules = $this->scheduleService->listSchedules();
        $appointments = $this->scheduleService->listAppointments(['date' => $date]);
        $displayState = $this->scheduleService->getDisplayQueueState($date);
        $metrics = $this->scheduleService->getQueueMetrics($date);

        $rooms = function_exists('facilities') ? facilities()->listRooms() : [];
        $spaces = function_exists('facilities') ? facilities()->listSpaces() : [];

        if ($this->inertia) {
            return $this->inertia->render('Schedule/Index', [
                'schedules' => $schedules,
                'appointments' => $appointments,
                'displayState' => $displayState,
                'metrics' => $metrics,
                'rooms' => $rooms,
                'spaces' => $spaces,
                'currentDate' => $date,
                'currentTenant' => $tenant ? [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                ] : null,
            ]);
        }

        return Response::json([
            'schedules' => $schedules,
            'appointments' => $appointments,
            'display_state' => $displayState,
            'metrics' => $metrics,
            'rooms' => $rooms,
            'spaces' => $spaces,
        ]);
    }

    public function displayScreen(Request $request): Response
    {
        $date = (string) $request->query('date', date('Y-m-d'));
        $displayState = $this->scheduleService->getDisplayQueueState($date);
        $tenant = $this->auth->currentTenant();

        if ($this->inertia) {
            return $this->inertia->render('Schedule/Display', [
                'displayState' => $displayState,
                'currentTenant' => $tenant ? [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                ] : null,
            ]);
        }

        return Response::json($displayState);
    }

    public function storeSchedule(Request $request): Response
    {
        try {
            $this->scheduleService->createSchedule([
                'day_of_week' => (int) $request->input('day_of_week', 1),
                'start_time' => (string) $request->input('start_time', '08:00'),
                'end_time' => (string) $request->input('end_time', '12:00'),
                'quota' => (int) $request->input('quota', 30),
                'room_id' => $request->input('room_id') ? (int) $request->input('room_id') : null,
                'staff_contact_id' => $request->input('staff_contact_id') ? (int) $request->input('staff_contact_id') : null,
            ]);

            $this->session->flash('success', 'Jadwal kerja berhasil ditambahkan.');
            return Response::redirect('/schedule');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/schedule');
        }
    }

    public function deleteSchedule(int $id): Response
    {
        try {
            $this->scheduleService->deleteSchedule($id);
            $this->session->flash('success', 'Jadwal berhasil dihapus.');
            return Response::redirect('/schedule');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/schedule');
        }
    }

    public function storeAppointment(Request $request): Response
    {
        try {
            $this->scheduleService->createAppointment([
                'client_name' => (string) $request->input('client_name'),
                'client_phone' => $request->input('client_phone') ? (string) $request->input('client_phone') : null,
                'appointment_date' => (string) $request->input('appointment_date', date('Y-m-d')),
                'start_time' => (string) $request->input('start_time', '09:00'),
                'room_id' => $request->input('room_id') ? (int) $request->input('room_id') : null,
                'staff_contact_id' => $request->input('staff_contact_id') ? (int) $request->input('staff_contact_id') : null,
                'notes' => $request->input('notes') ? (string) $request->input('notes') : null,
            ]);

            $this->session->flash('success', 'Pemesanan appointment berhasil disimpan.');
            return Response::redirect('/schedule');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/schedule');
        }
    }

    public function checkInAppointment(Request $request, int $id): Response
    {
        try {
            $prefix = (string) $request->input('prefix', 'A');
            $roomId = $request->input('room_id') ? (int) $request->input('room_id') : null;

            $result = $this->scheduleService->checkInAppointment($id, $prefix, $roomId);
            $ticketNumber = $result['ticket']['ticket_number'] ?? '';

            $this->session->flash('success', "Pasien berhasil check-in. Nomor antrean: $ticketNumber");
            return Response::redirect('/schedule');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/schedule');
        }
    }

    public function cancelAppointment(Request $request, int $id): Response
    {
        try {
            $reason = (string) $request->input('reason', 'Dibatalkan oleh pasien');
            $this->scheduleService->cancelAppointment($id, $reason);

            $this->session->flash('success', 'Appointment telah dibatalkan.');
            return Response::redirect('/schedule');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/schedule');
        }
    }

    public function issueTicket(Request $request): Response
    {
        try {
            $prefix = (string) $request->input('prefix', 'A');
            $serviceName = (string) $request->input('service_name', 'Poli Umum');
            $clientName = (string) $request->input('client_name', 'Pengunjung Umum');
            $roomId = $request->input('room_id') ? (int) $request->input('room_id') : null;

            $ticket = $this->scheduleService->issueQueueTicket([
                'prefix' => $prefix,
                'service_name' => $serviceName,
                'client_name' => $clientName,
                'room_id' => $roomId,
            ]);

            $this->session->flash('success', "Tiket {$ticket['ticket_number']} berhasil diterbitkan.");
            return Response::redirect('/schedule');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/schedule');
        }
    }

    public function callNext(Request $request): Response
    {
        try {
            $user = $this->auth->user();
            $prefix = (string) $request->input('prefix', 'A');
            $roomId = $request->input('room_id') ? (int) $request->input('room_id') : null;
            $spaceId = $request->input('space_id') ? (int) $request->input('space_id') : null;

            $called = $this->scheduleService->callNextTicket(
                prefix: $prefix,
                roomId: $roomId,
                spaceId: $spaceId,
                userId: $user ? (int) $user->id : null
            );

            if (!$called) {
                $this->session->flash('info', "Tidak ada antrean yang menunggu untuk kelompok $prefix.");
            } else {
                $this->session->flash('success', "Memanggil nomor antrean {$called['ticket_number']}.");
            }

            return Response::redirect('/schedule');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/schedule');
        }
    }

    public function recall(int $id): Response
    {
        try {
            $ticket = $this->scheduleService->recallTicket($id);
            $this->session->flash('success', "Memanggil ulang tiket {$ticket['ticket_number']} (Panggilan ke-{$ticket['call_count']}).");
            return Response::redirect('/schedule');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/schedule');
        }
    }

    public function serving(int $id): Response
    {
        try {
            $ticket = $this->scheduleService->startServing($id);
            $this->session->flash('success', "Mulai melayani tiket {$ticket['ticket_number']}.");
            return Response::redirect('/schedule');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/schedule');
        }
    }

    public function complete(Request $request, int $id): Response
    {
        try {
            $notes = $request->input('notes') ? (string) $request->input('notes') : null;
            $ticket = $this->scheduleService->completeTicket($id, $notes);
            $this->session->flash('success', "Pelayanan tiket {$ticket['ticket_number']} selesai.");
            return Response::redirect('/schedule');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/schedule');
        }
    }

    public function skip(Request $request, int $id): Response
    {
        try {
            $reason = (string) $request->input('reason', 'Tidak hadir');
            $ticket = $this->scheduleService->skipTicket($id, $reason);
            $this->session->flash('warning', "Tiket {$ticket['ticket_number']} dilewati (No-Show).");
            return Response::redirect('/schedule');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/schedule');
        }
    }

    public function transfer(Request $request, int $id): Response
    {
        try {
            $targetPrefix = (string) $request->input('target_prefix', 'B');
            $targetRoomId = $request->input('target_room_id') ? (int) $request->input('target_room_id') : null;
            $notes = $request->input('notes') ? (string) $request->input('notes') : null;

            $newTicket = $this->scheduleService->transferTicket($id, $targetPrefix, $targetRoomId, $notes);
            $this->session->flash('success', "Tiket berhasil ditransfer ke {$newTicket['ticket_number']}.");
            return Response::redirect('/schedule');
        } catch (Throwable $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/schedule');
        }
    }
}
