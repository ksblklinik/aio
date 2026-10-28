<?php

declare(strict_types=1);

namespace App\Modules\Schedule\Contracts;

/**
 * Universal Contract untuk Penjadwalan, Janji Temu (Appointment), dan Antrean Manusia (Human Queue)
 */
interface ScheduleRegistryInterface
{
    // ==========================================
    // 1. Resource Schedule Management
    // ==========================================

    /**
     * Buat jadwal ketersediaan petugas/stok ruangan.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createSchedule(array $data): array;

    /**
     * Ambil data jadwal berdasarkan ID.
     *
     * @return array<string, mixed>|null
     */
    public function getSchedule(int $id): ?array;

    /**
     * Perbarui data jadwal.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateSchedule(int $id, array $data): array;

    /**
     * Hapus jadwal ketersediaan.
     */
    public function deleteSchedule(int $id): bool;

    /**
     * Ambil daftar jadwal ketersediaan dengan filter.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listSchedules(array $filters = []): array;

    // ==========================================
    // 2. Appointment Booking Management
    // ==========================================

    /**
     * Buat pemesanan janji temu baru (appointment booking).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createAppointment(array $data): array;

    /**
     * Ambil data appointment berdasarkan ID.
     *
     * @return array<string, mixed>|null
     */
    public function getAppointment(int $id): ?array;

    /**
     * Ambil data appointment berdasarkan nomor unik booking.
     *
     * @return array<string, mixed>|null
     */
    public function getAppointmentByNumber(string $number): ?array;

    /**
     * Perbarui data appointment.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateAppointment(int $id, array $data): array;

    /**
     * Check-in pasien/klien saat tiba di lokasi, otomatis menerbitkan tiket antrean.
     *
     * @return array<string, mixed> Appointment data beserta queue ticket yang diterbitkan
     */
    public function checkInAppointment(int $id, ?string $targetPrefix = null, ?int $roomId = null): array;

    /**
     * Ambil daftar appointment dengan filter.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listAppointments(array $filters = []): array;

    /**
     * Batalkan appointment.
     *
     * @return array<string, mixed>
     */
    public function cancelAppointment(int $id, ?string $reason = null): array;

    // ==========================================
    // 3. Human Queue Engine (Antrean Fisik)
    // ==========================================

    /**
     * Terbitkan tiket antrean baru (walk-in kiosk atau integrasi sistem).
     * Nomor urut direset harian secara atomik (e.g. A-001, B-001).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function issueQueueTicket(array $data): array;

    /**
     * Ambil tiket antrean berdasarkan ID.
     *
     * @return array<string, mixed>|null
     */
    public function getQueueTicket(int $id): ?array;

    /**
     * Ambil tiket antrean berdasarkan nomor tiket dan tanggal.
     *
     * @return array<string, mixed>|null
     */
    public function getQueueTicketByNumber(string $ticketNumber, ?string $date = null): ?array;

    /**
     * Panggil nomor antrean berikutnya yang berstatus 'waiting' untuk prefix tertentu.
     * Mengubah status menjadi 'called' dan merekam waktu pemanggilan.
     *
     * @return array<string, mixed>|null Mengembalikan null jika tidak ada antrean menunggu
     */
    public function callNextTicket(string $prefix, ?int $roomId = null, ?int $spaceId = null, ?int $userId = null): ?array;

    /**
     * Panggil ulang (recall) nomor antrean yang sedang aktif/terpanggil.
     *
     * @return array<string, mixed>
     */
    public function recallTicket(int $ticketId): array;

    /**
     * Mulai melayani tiket yang telah dipanggil (status -> 'serving').
     *
     * @return array<string, mixed>
     */
    public function startServing(int $ticketId): array;

    /**
     * Selesaikan pelayanan tiket antrean (status -> 'completed').
     *
     * @return array<string, mixed>
     */
    public function completeTicket(int $ticketId, ?string $notes = null): array;

    /**
     * Lewati antrean karena pasien/klien tidak hadir (status -> 'skipped').
     *
     * @return array<string, mixed>
     */
    public function skipTicket(int $ticketId, ?string $reason = null): array;

    /**
     * Transfer antrean ke poli/loket/layanan berikutnya (e.g. dari Poli ke Farmasi/Kasir).
     * Menerbitkan tiket baru yang terhubung ke siklus antrean berikutnya.
     *
     * @return array<string, mixed> Data tiket baru hasil transfer
     */
    public function transferTicket(int $ticketId, string $targetPrefix, ?int $targetRoomId = null, ?string $notes = null): array;

    // ==========================================
    // 4. Live Display & Queue Analytics
    // ==========================================

    /**
     * Ambil state antrean aktif saat ini untuk ditampilkan pada layar TV ruang tunggu.
     *
     * @return array<string, mixed>
     */
    public function getDisplayQueueState(?string $date = null): array;

    /**
     * Ambil daftar tiket antrean yang masih menunggu (waiting) per prefix.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listWaitingTickets(string $prefix, ?string $date = null): array;

    /**
     * Dapatkan metrik performa antrean (total tiket, rata-rata waktu tunggu, dll).
     *
     * @return array<string, mixed>
     */
    public function getQueueMetrics(?string $date = null): array;
}
