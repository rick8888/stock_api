<?php

namespace Tests\Unit;

use App\Services\Replenishment\ServiceLevel;
use PHPUnit\Framework\TestCase;

class ServiceLevelTest extends TestCase
{
    public function test_z_score_matches_reference_quantiles(): void
    {
        $this->assertEqualsWithDelta(1.6449, ServiceLevel::zScore(0.95), 0.0005);
        $this->assertEqualsWithDelta(2.0537, ServiceLevel::zScore(0.98), 0.0005);
        $this->assertEqualsWithDelta(1.2816, ServiceLevel::zScore(0.90), 0.0005);
        $this->assertEqualsWithDelta(2.3263, ServiceLevel::zScore(0.99), 0.0005);
        $this->assertEqualsWithDelta(0.0, ServiceLevel::zScore(0.50), 0.0005);
    }

    public function test_out_of_range_service_level_falls_back_to_default(): void
    {
        $expected = ServiceLevel::zScore(ServiceLevel::DEFAULT);

        $this->assertSame($expected, ServiceLevel::zScore(0.0));
        $this->assertSame($expected, ServiceLevel::zScore(1.0));
        $this->assertSame($expected, ServiceLevel::zScore(-2.0));
    }

    public function test_abc_class_drives_service_level(): void
    {
        $this->assertSame(0.98, ServiceLevel::resolve(null, 'A'));
        $this->assertSame(0.95, ServiceLevel::resolve(null, 'B'));
        $this->assertSame(0.90, ServiceLevel::resolve(null, 'C'));
        $this->assertSame(ServiceLevel::DEFAULT, ServiceLevel::resolve(null, null));
    }

    public function test_product_level_takes_precedence_over_abc_class(): void
    {
        $this->assertSame(0.995, ServiceLevel::resolve(0.995, 'C'));
    }

    public function test_invalid_product_level_falls_back_to_abc_class(): void
    {
        $this->assertSame(0.98, ServiceLevel::resolve(0.0, 'A'));
        $this->assertSame(0.98, ServiceLevel::resolve(1.5, 'A'));
    }
}
