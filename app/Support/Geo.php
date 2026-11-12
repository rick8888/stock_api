<?php

namespace App\Support;

/**
 * Helpers géographiques purs : distance, projection sur un tracé, progression.
 */
final class Geo
{
    private const EARTH_KM = 6371.0;

    /** @param  array{lat: float, lng: float}  $a
     *  @param  array{lat: float, lng: float}  $b */
    public static function haversineKm(array $a, array $b): float
    {
        $dLat = deg2rad($b['lat'] - $a['lat']);
        $dLng = deg2rad($b['lng'] - $a['lng']);
        $lat1 = deg2rad($a['lat']);
        $lat2 = deg2rad($b['lat']);
        $h = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_KM * asin(min(1.0, sqrt($h)));
    }

    /**
     * Longueur cumulée d'un tracé en km.
     *
     * @param  list<array{lat: float, lng: float}>  $path
     */
    public static function pathLengthKm(array $path): float
    {
        $total = 0.0;
        for ($i = 0; $i < count($path) - 1; $i++) {
            $total += self::haversineKm($path[$i], $path[$i + 1]);
        }

        return $total;
    }

    /**
     * Projette un point GPS sur le tracé le plus proche.
     *
     * @param  list<array{lat: float, lng: float, label?: string}>  $path
     * @return array{
     *   lat: float,
     *   lng: float,
     *   progress: float,
     *   distanceAlongKm: float,
     *   remainingKm: float,
     *   totalKm: float,
     *   segmentIndex: int
     * }
     */
    public static function projectOntoPath(array $path, float $lat, float $lng): array
    {
        if (! $path) {
            return [
                'lat' => $lat,
                'lng' => $lng,
                'progress' => 0.0,
                'distanceAlongKm' => 0.0,
                'remainingKm' => 0.0,
                'totalKm' => 0.0,
                'segmentIndex' => 0,
            ];
        }

        if (count($path) === 1) {
            return [
                'lat' => (float) $path[0]['lat'],
                'lng' => (float) $path[0]['lng'],
                'progress' => 0.0,
                'distanceAlongKm' => 0.0,
                'remainingKm' => 0.0,
                'totalKm' => 0.0,
                'segmentIndex' => 0,
            ];
        }

        $point = ['lat' => $lat, 'lng' => $lng];
        $best = null;
        $bestDistance = PHP_FLOAT_MAX;
        $prefix = 0.0;
        $atPrefix = 0.0;
        $segmentIndex = 0;

        for ($i = 0; $i < count($path) - 1; $i++) {
            $a = $path[$i];
            $b = $path[$i + 1];
            $leg = self::haversineKm($a, $b);
            $projected = self::closestOnSegment($a, $b, $point);
            $dist = self::haversineKm($point, $projected);
            if ($dist < $bestDistance) {
                $bestDistance = $dist;
                $best = $projected;
                $atPrefix = $prefix + ($leg * $projected['t']);
                $segmentIndex = $i;
            }
            $prefix += $leg;
        }

        $total = max($prefix, 0.0001);
        $along = min($total, max(0.0, $atPrefix));

        return [
            'lat' => (float) ($best['lat'] ?? $lat),
            'lng' => (float) ($best['lng'] ?? $lng),
            'progress' => round(($along / $total) * 100, 2),
            'distanceAlongKm' => round($along, 3),
            'remainingKm' => round(max(0.0, $total - $along), 3),
            'totalKm' => round($total, 3),
            'segmentIndex' => $segmentIndex,
        ];
    }

    /**
     * Point le plus proche sur le segment AB, avec paramètre t ∈ [0,1].
     *
     * @param  array{lat: float, lng: float}  $a
     * @param  array{lat: float, lng: float}  $b
     * @param  array{lat: float, lng: float}  $p
     * @return array{lat: float, lng: float, t: float}
     */
    public static function closestOnSegment(array $a, array $b, array $p): array
    {
        $ax = $a['lng'];
        $ay = $a['lat'];
        $bx = $b['lng'];
        $by = $b['lat'];
        $px = $p['lng'];
        $py = $p['lat'];
        $dx = $bx - $ax;
        $dy = $by - $ay;
        $len2 = ($dx * $dx) + ($dy * $dy);
        if ($len2 <= 0.0) {
            return ['lat' => (float) $ay, 'lng' => (float) $ax, 't' => 0.0];
        }
        $t = (($px - $ax) * $dx + ($py - $ay) * $dy) / $len2;
        $t = max(0.0, min(1.0, $t));

        return [
            'lat' => (float) ($ay + $t * $dy),
            'lng' => (float) ($ax + $t * $dx),
            't' => $t,
        ];
    }

    /**
     * Interpoler un point le long d'un tracé (t ∈ [0,1]).
     *
     * @param  list<array{lat: float, lng: float, label?: string}>  $path
     * @return array{lat: float, lng: float, label?: string}
     */
    public static function pointAlong(array $path, float $t): array
    {
        if (! $path) {
            return ['lat' => 0.0, 'lng' => 0.0];
        }
        if (count($path) === 1 || $t <= 0) {
            return $path[0];
        }
        if ($t >= 1) {
            return $path[count($path) - 1];
        }

        $lengths = [];
        $total = 0.0;
        for ($i = 0; $i < count($path) - 1; $i++) {
            $len = self::haversineKm($path[$i], $path[$i + 1]);
            $lengths[] = $len;
            $total += $len;
        }
        $remaining = max($total, 0.0001) * $t;
        for ($i = 0; $i < count($lengths); $i++) {
            $len = max($lengths[$i], 0.0001);
            if ($remaining <= $len) {
                $ratio = $remaining / $len;

                return [
                    'lat' => $path[$i]['lat'] + ($path[$i + 1]['lat'] - $path[$i]['lat']) * $ratio,
                    'lng' => $path[$i]['lng'] + ($path[$i + 1]['lng'] - $path[$i]['lng']) * $ratio,
                    'label' => $path[$i]['label'] ?? null,
                ];
            }
            $remaining -= $len;
        }

        return $path[count($path) - 1];
    }

    /**
     * Découpe un tracé selon une progression (0–1) : trail déjà parcouru + reste.
     *
     * @param  list<array{lat: float, lng: float, label?: string}>  $path
     * @return array{done: list<array{lat: float, lng: float, label?: string}>, rest: list<array{lat: float, lng: float, label?: string}>}
     */
    public static function splitPath(array $path, float $t): array
    {
        if (! $path) {
            return ['done' => [], 'rest' => []];
        }
        if ($t <= 0) {
            return ['done' => [$path[0]], 'rest' => $path];
        }
        if ($t >= 1) {
            return ['done' => $path, 'rest' => [end($path)]];
        }
        $cursor = self::pointAlong($path, $t);
        $lengths = [];
        $total = 0.0;
        for ($i = 0; $i < count($path) - 1; $i++) {
            $len = self::haversineKm($path[$i], $path[$i + 1]);
            $lengths[] = $len;
            $total += $len;
        }
        $target = max($total, 0.0001) * $t;
        $done = [$path[0]];
        $seg = 0;
        for (; $seg < count($lengths); $seg++) {
            $len = max($lengths[$seg], 0.0001);
            if ($target <= $len) {
                break;
            }
            $done[] = $path[$seg + 1];
            $target -= $len;
        }
        $done[] = $cursor;
        $rest = [$cursor];
        for ($i = $seg + 1; $i < count($path); $i++) {
            $rest[] = $path[$i];
        }

        return ['done' => $done, 'rest' => $rest];
    }
}
