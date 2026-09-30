<?php

namespace Webkul\Employee\Services\Attendance;

/**
 * Pure server-side great-circle distance (Haversine), in metres. The
 * client never supplies a distance; this is the only place one is computed.
 * A spherical model is within ~0.5% of WGS-84, far below GPS error.
 */
final class GeoDistanceCalculator
{
    public const EARTH_MEAN_RADIUS_METERS = 6371008.8;

    public function isValidCoordinate(mixed $latitude, mixed $longitude): bool
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return false;
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;

        if (! is_finite($latitude) || ! is_finite($longitude)) {
            return false;
        }

        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return false;
        }

        // (0, 0) "null island" is what broken location providers return.
        return ! ($latitude === 0.0 && $longitude === 0.0);
    }

    public function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $deltaPhi = $phi2 - $phi1;
        $deltaLambda = deg2rad($lng2 - $lng1);

        $a = sin($deltaPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($deltaLambda / 2) ** 2;
        $a = min(1.0, max(0.0, $a));

        return self::EARTH_MEAN_RADIUS_METERS * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
