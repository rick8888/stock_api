<?php

namespace Tests\Unit;

use App\Support\Geo;
use PHPUnit\Framework\TestCase;

class GeoTest extends TestCase
{
    public function test_haversine_is_zero_for_the_same_point(): void
    {
        $point = ['lat' => 33.5731, 'lng' => -7.5898];

        $this->assertSame(0.0, Geo::haversineKm($point, $point));
    }

    public function test_haversine_casablanca_to_rabat_is_about_85_km(): void
    {
        $casa = ['lat' => 33.5731, 'lng' => -7.5898];
        $rabat = ['lat' => 34.0209, 'lng' => -6.8416];

        $km = Geo::haversineKm($casa, $rabat);

        $this->assertGreaterThan(80.0, $km);
        $this->assertLessThan(100.0, $km);
    }

    public function test_projection_at_the_start_of_the_path(): void
    {
        $path = [
            ['lat' => 0.0, 'lng' => 0.0],
            ['lat' => 0.0, 'lng' => 1.0],
        ];

        $result = Geo::projectOntoPath($path, 0.0, 0.0);

        $this->assertEqualsWithDelta(0.0, $result['progress'], 0.1);
        $this->assertEqualsWithDelta(0.0, $result['distanceAlongKm'], 0.01);
    }

    public function test_projection_at_the_midpoint(): void
    {
        $path = [
            ['lat' => 0.0, 'lng' => 0.0],
            ['lat' => 0.0, 'lng' => 1.0],
        ];

        $result = Geo::projectOntoPath($path, 0.0, 0.5);

        $this->assertEqualsWithDelta(50.0, $result['progress'], 1.0);
        $this->assertEqualsWithDelta($result['totalKm'] / 2, $result['distanceAlongKm'], 0.5);
    }

    public function test_projection_snaps_off_path_points_onto_the_segment(): void
    {
        $path = [
            ['lat' => 0.0, 'lng' => 0.0],
            ['lat' => 0.0, 'lng' => 1.0],
        ];

        $result = Geo::projectOntoPath($path, 0.2, 0.5);

        $this->assertEqualsWithDelta(0.0, $result['lat'], 0.01);
        $this->assertEqualsWithDelta(0.5, $result['lng'], 0.01);
        $this->assertEqualsWithDelta(50.0, $result['progress'], 1.0);
    }

    public function test_point_along_returns_endpoints(): void
    {
        $path = [
            ['lat' => 1.0, 'lng' => 2.0, 'label' => 'A'],
            ['lat' => 3.0, 'lng' => 4.0, 'label' => 'B'],
        ];

        $start = Geo::pointAlong($path, 0);
        $end = Geo::pointAlong($path, 1);

        $this->assertSame(1.0, $start['lat']);
        $this->assertSame(3.0, $end['lat']);
    }
}
