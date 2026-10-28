<?php

declare(strict_types=1);

namespace Core\Security;

class IntegrityGuard
{
    /**
     * Radius rata-rata bumi dalam kilometer.
     */
    public const EARTH_RADIUS_KM = 6371.0;

    /**
     * Daftar domain penyedia email sementara (disposable / burner email) populer.
     */
    protected const DISPOSABLE_DOMAINS = [
        'mailinator.com',
        '10minutemail.com',
        'tempmail.com',
        'temp-mail.org',
        'temp-mail.io',
        'guerrillamail.com',
        'guerrillamail.block',
        'trashmail.com',
        'yopmail.com',
        'yopmail.net',
        'fakeinbox.com',
        'dispostable.com',
        'throwawaymail.com',
        'getnada.com',
        'mohmal.com',
        'sharklasers.com',
        'mytemp.email',
        'crazymailing.com',
        'burnermail.io',
        'maildrop.cc',
    ];

    public function __construct(
        private string $appKey = ''
    ) {
        if ($this->appKey === '') {
            $this->appKey = \Core\Security\AppKey::get();
        }
    }

    /**
     * Hitung jarak jarak lingkaran besar (Great-Circle Distance) antara dua titik koordinat (km).
     */
    public function calculateDistanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_KM * $c;
    }

    /**
     * Hitung kecepatan perpindahan antara dua titik koordinat dan waktu (km/jam).
     */
    public function calculateSpeedKmh(
        float $lat1,
        float $lon1,
        int $time1,
        float $lat2,
        float $lon2,
        int $time2
    ): float {
        $deltaSeconds = abs($time2 - $time1);
        if ($deltaSeconds === 0) {
            return 0.0;
        }

        $distanceKm = $this->calculateDistanceKm($lat1, $lon1, $lat2, $lon2);
        $hours = $deltaSeconds / 3600.0;

        return $distanceKm / $hours;
    }

    /**
     * Evaluasi apakah perpindahan antara dua titik melampaui batas kecepatan fisik manusia (Impossible Travel).
     */
    public function isImpossibleTravel(
        float $lat1,
        float $lon1,
        int $time1,
        float $lat2,
        float $lon2,
        int $time2,
        float $maxSpeedKmh = 1000.0
    ): bool {
        $deltaSeconds = abs($time2 - $time1);
        // Jika perpindahan memiliki jarak > 100 meter pada detik yang persis sama, anggap impossible travel
        $distanceKm = $this->calculateDistanceKm($lat1, $lon1, $lat2, $lon2);
        if ($deltaSeconds === 0) {
            return $distanceKm > 0.1;
        }

        $speed = $distanceKm / ($deltaSeconds / 3600.0);
        return $speed > $maxSpeedKmh;
    }

    /**
     * Validasi metadata telemetri lokasi GPS untuk mendeteksi tanda-tanda Mock Location / Spoofing.
     *
     * @param array<string, mixed> $telemetry
     * @return array{valid: bool, flags: array<string>, details: array<string, mixed>}
     */
    public function validateLocation(array $telemetry): array
    {
        $flags = [];
        $lat = isset($telemetry['latitude']) ? (float) $telemetry['latitude'] : null;
        $lon = isset($telemetry['longitude']) ? (float) $telemetry['longitude'] : null;

        if ($lat === null || $lon === null) {
            $flags[] = 'missing_coordinates';
        } elseif ($lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0) {
            $flags[] = 'invalid_coordinates_range';
        }

        // Cek flag mock provider dari OS mobile
        if (!empty($telemetry['is_mock']) || !empty($telemetry['mocked'])) {
            $flags[] = 'mock_location_detected';
        }

        // Cek anomali akurasi: akurasi minus atau exact zero konstan sering dihasilkan mock generator
        if (isset($telemetry['accuracy'])) {
            $acc = (float) $telemetry['accuracy'];
            if ($acc <= 0.0) {
                $flags[] = 'zero_or_negative_accuracy';
            }
        }

        return [
            'valid' => empty($flags),
            'flags' => $flags,
            'details' => [
                'latitude' => $lat,
                'longitude' => $lon,
                'accuracy' => $telemetry['accuracy'] ?? null,
            ],
        ];
    }

    /**
     * Deteksi penyimpangan waktu perangkat lokal pengguna terhadap waktu atomik server (Clock Tampering).
     */
    public function detectTimeDrift(
        int $clientTimestamp,
        int $toleranceSeconds = 120,
        ?int $serverTimestamp = null
    ): bool {
        $serverTimestamp ??= time();
        $drift = abs($serverTimestamp - $clientTimestamp);

        return $drift > $toleranceSeconds;
    }

    /**
     * Bangkitkan hash sidik jari perangkat fisik unik yang stabil dan aman dari timing attack.
     *
     * @param array<string, mixed> $attributes
     */
    public function generateDeviceHash(array $attributes, string $salt = ''): string
    {
        ksort($attributes);
        $payload = json_encode($attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        $key = $salt !== '' ? $salt : $this->appKey;

        return hash_hmac('sha256', $payload, $key);
    }

    /**
     * Verifikasi kesesuaian sidik jari perangkat terdaftar dengan request yang masuk.
     */
    public function verifyDeviceBinding(string $expectedHash, string $incomingHash): bool
    {
        if ($expectedHash === '' || $incomingHash === '') {
            return false;
        }

        return hash_equals($expectedHash, $incomingHash);
    }

    /**
     * Periksa apakah alamat email berasal dari penyedia email sementara (disposable / burner).
     */
    public function isDisposableEmail(string $email): bool
    {
        $parts = explode('@', trim($email));
        if (count($parts) !== 2) {
            return false;
        }

        $domain = strtolower($parts[1]);

        return in_array($domain, self::DISPOSABLE_DOMAINS, true);
    }
}
