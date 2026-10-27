<?php

declare(strict_types=1);

namespace Core\Geo;

use InvalidArgumentException;

class Geo
{
    public const EARTH_RADIUS_KM = 6371.0088;
    public const EARTH_RADIUS_METERS = 6371008.8;
    public const EARTH_RADIUS_MILES = 3958.7613;

    /**
     * Hitung jarak lingkaran besar antara dua koordinat geografis menggunakan rumus Haversine
     *
     * @param float $lat1 Latitude titik awal (-90 s.d 90)
     * @param float $lng1 Longitude titik awal (-180 s.d 180)
     * @param float $lat2 Latitude titik tujuan (-90 s.d 90)
     * @param float $lng2 Longitude titik tujuan (-180 s.d 180)
     * @param string $unit Satuan jarak: 'km' | 'm' | 'miles'
     * @return float Jarak dalam satuan yang diminta (dibulatkan ke 4 desimal)
     */
    public static function distance(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2,
        string $unit = 'km'
    ): float {
        self::validateCoordinates($lat1, $lng1);
        self::validateCoordinates($lat2, $lng2);

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        $radius = match (strtolower($unit)) {
            'm', 'meters' => self::EARTH_RADIUS_METERS,
            'miles', 'mi' => self::EARTH_RADIUS_MILES,
            default => self::EARTH_RADIUS_KM,
        };

        return round($radius * $c, 4);
    }

    /**
     * Periksa apakah suatu koordinat berada di dalam radius geofence (dalam satuan meter)
     */
    public static function inRadius(
        float $checkLat,
        float $checkLng,
        float $centerLat,
        float $centerLng,
        float $radiusMeters
    ): bool {
        $distMeters = self::distance($checkLat, $checkLng, $centerLat, $centerLng, 'm');
        return $distMeters <= $radiusMeters;
    }

    /**
     * Hitung arah azimut (bearing) dalam derajat (0 - 360°) dari titik awal ke titik tujuan
     */
    public static function bearing(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2
    ): float {
        self::validateCoordinates($lat1, $lng1);
        self::validateCoordinates($lat2, $lng2);

        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $dLngRad = deg2rad($lng2 - $lng1);

        $y = sin($dLngRad) * cos($lat2Rad);
        $x = cos($lat1Rad) * sin($lat2Rad) -
            sin($lat1Rad) * cos($lat2Rad) * cos($dLngRad);

        $bearingRad = atan2($y, $x);
        $bearingDeg = fmod(rad2deg($bearingRad) + 360.0, 360.0);

        return round($bearingDeg, 2);
    }

    private static function validateCoordinates(float $lat, float $lng): void
    {
        if ($lat < -90.0 || $lat > 90.0) {
            throw new InvalidArgumentException("Latitude must be between -90 and 90 degrees. Received: {$lat}");
        }
        if ($lng < -180.0 || $lng > 180.0) {
            throw new InvalidArgumentException("Longitude must be between -180 and 180 degrees. Received: {$lng}");
        }
    }
}
