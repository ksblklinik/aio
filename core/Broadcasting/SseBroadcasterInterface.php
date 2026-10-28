<?php

declare(strict_types=1);

namespace Core\Broadcasting;

interface SseBroadcasterInterface
{
    /**
     * Publikasikan event ke channel tertentu (terikat tenant).
     *
     * @param string $channel Nama channel (e.g. 'queue', 'notifications', 'seal', 'system')
     * @param string $event Nama event (e.g. 'ticket_called', 'notification_received')
     * @param array<string, mixed> $payload Data muatan JSON
     * @param string|null $tenantId ID tenant untuk isolasi channel (jika null, mengambil dari konteks tenant aktif)
     * @return int ID event unik yang dihasilkan
     */
    public function publish(string $channel, string $event, array $payload = [], ?string $tenantId = null): int;

    /**
     * Publikasikan event publik / global tanpa batasan tenant (tenant_id = NULL).
     *
     * @param string $channel
     * @param string $event
     * @param array<string, mixed> $payload
     * @return int
     */
    public function publishPublic(string $channel, string $event, array $payload = []): int;

    /**
     * Ambil daftar event terkini untuk channel tertentu setelah ID tertentu.
     *
     * @param string $channel
     * @param int|null $afterId Ambil hanya event dengan id > $afterId
     * @param string|null $tenantId
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function getEvents(string $channel, ?int $afterId = null, ?string $tenantId = null, int $limit = 50): array;

    /**
     * Ambil snapshot event terakhir dari sebuah channel.
     *
     * @param string $channel
     * @param string|null $tenantId
     * @return array<string, mixed>|null
     */
    public function getLatestEvent(string $channel, ?string $tenantId = null): ?array;

    /**
     * Hapus rekaman event usang yang melampaui batas waktu retensi.
     *
     * @param int $keepLastMinutes Jumlah menit retensi event (default: 60 menit)
     * @return int Jumlah baris yang berhasil dipangkas
     */
    public function prune(int $keepLastMinutes = 60): int;
}
