<?php

namespace App\Services\Hr\Attendance\Import;

/** Pure geography helpers for the optional clock-in location check. */
final class Geo
{
    private const EARTH_RADIUS_M = 6371008.8;

    /** Great-circle distance in metres (haversine). */
    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        [$p1, $p2] = [deg2rad($lat1), deg2rad($lat2)];
        $a = sin(($p2 - $p1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;
        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($a)));
    }

    public static function validCoordinates(mixed $lat, mixed $lng): bool
    {
        return is_numeric($lat) && is_numeric($lng) && abs((float) $lat) <= 90 && abs((float) $lng) <= 180;
    }

    /**
     * The first site whose circle contains the point.
     * @param iterable<object{id:string,latitude:mixed,longitude:mixed,radius_meters:mixed}> $sites
     */
    public static function insideSite(float $lat, float $lng, iterable $sites): ?object
    {
        foreach ($sites as $s) {
            if (self::distanceMeters($lat, $lng, (float) $s->latitude, (float) $s->longitude) <= (float) $s->radius_meters) {
                return $s;
            }
        }
        return null;
    }
}
