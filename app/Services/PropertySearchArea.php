<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class PropertySearchArea
{
    private const SCALE = 1000000;

    private const MAX_VERTICES = 64;

    public static function isPolygon(mixed $area): bool
    {
        return is_string($area) && str_starts_with($area, 'p:');
    }

    public static function apply(Builder $query, mixed $area): void
    {
        if (self::isPolygon($area)) {
            $ring = self::parseRing(substr($area, 2));
            $latitudes = array_column($ring, 0);
            $longitudes = array_column($ring, 1);
            $query->whereBetween('properties.latitude', [min($latitudes) / self::SCALE, max($latitudes) / self::SCALE])
                ->whereBetween('properties.longitude', [min($longitudes) / self::SCALE, max($longitudes) / self::SCALE]);
            self::filterInsideRing($query, $ring);

            return;
        }

        $parts = is_string($area) ? explode(',', $area) : [];
        if (count($parts) !== 4 || count(array_filter($parts, 'is_numeric')) !== 4) {
            throw ValidationException::withMessages([
                'area' => 'Укажите четыре координаты области поиска: юг, запад, север, восток.',
            ]);
        }
        [$south, $west, $north, $east] = array_map('floatval', $parts);
        if ($south < -90 || $north > 90 || $west < -180 || $east > 180 || $south >= $north || $west >= $east) {
            throw ValidationException::withMessages(['area' => 'Укажите корректные границы области поиска.']);
        }
        $query->whereBetween('properties.latitude', [$south, $north])
            ->whereBetween('properties.longitude', [$west, $east]);
    }

    /** @return list<array{int, int}> Coordinates in microdegrees for exact orientation tests. */
    private static function parseRing(string $value): array
    {
        $vertices = explode(';', $value, self::MAX_VERTICES + 1);
        $count = count($vertices);
        if ($count < 3 || $count > self::MAX_VERTICES) {
            throw ValidationException::withMessages(['area' => 'Контур должен содержать от 3 до 64 точек.']);
        }

        $ring = [];
        foreach ($vertices as $vertex) {
            if (! preg_match('/\A(-?\d{1,3}(?:\.\d{1,6})?),(-?\d{1,3}(?:\.\d{1,6})?)\z/', $vertex, $match)
                || abs((float) $match[1]) > 90 || abs((float) $match[2]) > 180) {
                throw ValidationException::withMessages(['area' => 'Укажите корректные координаты контура.']);
            }
            $ring[] = [(int) round((float) $match[1] * self::SCALE), (int) round((float) $match[2] * self::SCALE)];
        }

        for ($i = 0; $i < $count; $i++) {
            $a = $ring[$i];
            $b = $ring[($i + 1) % $count];
            $c = $ring[($i + 2) % $count];
            if (abs($b[1] - $a[1]) > 180 * self::SCALE) {
                throw ValidationException::withMessages(['area' => 'Обведите область по одну сторону от меридиана 180°.']);
            }
            // A zero-length edge or collinear reversal overlaps the previous segment.
            $dot = ($b[0] - $a[0]) * ($c[0] - $b[0]) + ($b[1] - $a[1]) * ($c[1] - $b[1]);
            if (self::cross($a, $b, $c) === 0 && $dot <= 0) {
                throw ValidationException::withMessages(['area' => 'Контур должен охватывать площадь и не пересекать себя.']);
            }
            for ($j = $i + 2; $j < $count; $j++) {
                if ($i === 0 && $j === $count - 1) {
                    continue; // First and last segments share an endpoint.
                }
                if (self::segmentsIntersect($a, $b, $ring[$j], $ring[($j + 1) % $count])) {
                    throw ValidationException::withMessages(['area' => 'Линии контура не должны пересекаться.']);
                }
            }
        }

        return $ring;
    }

    private static function cross(array $a, array $b, array $p): int
    {
        return ($b[1] - $a[1]) * ($p[0] - $a[0]) - ($b[0] - $a[0]) * ($p[1] - $a[1]);
    }

    private static function segmentsIntersect(array $a, array $b, array $c, array $d): bool
    {
        foreach ([0, 1] as $axis) {
            if (max($a[$axis], $b[$axis]) < min($c[$axis], $d[$axis])
                || max($c[$axis], $d[$axis]) < min($a[$axis], $b[$axis])) {
                return false;
            }
        }

        // Compare signs rather than multiplying the two integer cross products.
        return (self::cross($a, $b, $c) <=> 0) * (self::cross($a, $b, $d) <=> 0) <= 0
            && (self::cross($c, $d, $a) <=> 0) * (self::cross($c, $d, $b) <=> 0) <= 0;
    }

    private static function filterInsideRing(Builder $query, array $ring): void
    {
        $bindings = [];
        $count = count($ring);
        for ($i = 0; $i < $count; $i++) {
            [$y1, $x1] = $ring[$i];
            [$y2, $x2] = $ring[($i + 1) % $count];
            if ($y1 > $y2) {
                [$y1, $x1, $y2, $x2] = [$y2, $x2, $y1, $x1];
            }
            array_push($bindings, $y1 / self::SCALE, $x1 / self::SCALE, $y2 / self::SCALE, $x2 / self::SCALE);
        }

        // Cast bound parameters because PDO can bind decimal values as strings.
        $edge = 'SELECT CAST(? AS DECIMAL(12,6)) AS y1, CAST(? AS DECIMAL(12,6)) AS x1, '
            .'CAST(? AS DECIMAL(12,6)) AS y2, CAST(? AS DECIMAL(12,6)) AS x2';
        $edges = implode(' UNION ALL ', array_fill(0, $count, $edge));
        $lat = 'properties.latitude';
        $lng = 'properties.longitude';
        $cross = "((x2 - x1) * ($lat - y1) - (y2 - y1) * ($lng - x1))";

        // Count eastward ray crossings. Half-open latitude intervals count vertices once.
        // Check the boundary separately, allowing for SQLite floating-point round-off.
        $query->whereRaw("(
            SELECT CASE WHEN MAX(CASE WHEN $lat BETWEEN y1 AND y2
                AND (($lng BETWEEN x1 AND x2) OR ($lng BETWEEN x2 AND x1))
                AND ABS($cross) <= 1e-12 * (ABS(x2 - x1) + y2 - y1)
                THEN 1 ELSE 0 END) = 1 THEN 1
            ELSE SUM(CASE WHEN $lat >= y1 AND $lat < y2 AND $cross > 0 THEN 1 ELSE 0 END) % 2 END
            FROM ($edges) AS contour_edges
        ) = 1", $bindings);
    }
}
