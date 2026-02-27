<?php

namespace App\Services;

use App\Models\Location;

class GeoFenceService
{
    /**
     * Check if a point (lat, lng) falls within any active location boundary.
     * Returns the matched location or null.
     */
    public static function isPointAllowed(float $lat, float $lng): ?Location
    {
        // Check from most specific (area) to broadest (country)
        foreach (['area', 'city', 'country'] as $level) {
            $locations = Location::where('level', $level)
                ->where('is_active', true)
                ->whereNotNull('boundary')
                ->get();

            foreach ($locations as $location) {
                if (self::pointInPolygon($lat, $lng, $location->boundary)) {
                    return $location;
                }
            }
        }

        return null;
    }

    /**
     * Check if a location_id belongs to an active, allowed location.
     */
    public static function isLocationAllowed(int $locationId): bool
    {
        $location = Location::find($locationId);
        if (!$location || !$location->is_active) {
            return false;
        }

        // Walk up the parent chain to verify all ancestors are active
        $current = $location;
        while ($current->parent_id) {
            $parent = Location::find($current->parent_id);
            if (!$parent || !$parent->is_active) {
                return false;
            }
            $current = $parent;
        }

        return true;
    }

    /**
     * Ray-casting algorithm for point-in-polygon.
     * Boundary is a GeoJSON-style array of [lng, lat] coordinate pairs.
     */
    public static function pointInPolygon(float $lat, float $lng, array $boundary): bool
    {
        // Support GeoJSON Polygon format: { type: "Polygon", coordinates: [[[lng,lat], ...]] }
        $coords = $boundary;
        if (isset($boundary['type']) && $boundary['type'] === 'Polygon') {
            $coords = $boundary['coordinates'][0] ?? [];
        } elseif (isset($boundary['coordinates'])) {
            $coords = $boundary['coordinates'][0] ?? $boundary['coordinates'];
        }

        if (count($coords) < 3) {
            return false;
        }

        $inside = false;
        $n = count($coords);

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = $coords[$i][1] ?? 0; // lat
            $yi = $coords[$i][0] ?? 0; // lng
            $xj = $coords[$j][1] ?? 0;
            $yj = $coords[$j][0] ?? 0;

            $intersect = (($yi > $lng) !== ($yj > $lng))
                && ($lat < ($xj - $xi) * ($lng - $yi) / ($yj - $yi) + $xi);

            if ($intersect) {
                $inside = !$inside;
            }
        }

        return $inside;
    }
}
