<?php

namespace Tests\Unit;

use App\Services\Replenishment\DemandStatistics;
use PHPUnit\Framework\TestCase;

class DemandStatisticsTest extends TestCase
{
    public function test_empty_history_yields_zero_demand(): void
    {
        $stats = DemandStatistics::fromDailyTotals([], 0);

        $this->assertSame(0.0, $stats['avgDailyDemand']);
        $this->assertSame(0.0, $stats['demandStdDev']);
        $this->assertSame(0, $stats['sampleDays']);
    }

    public function test_days_without_issue_count_as_zero_in_the_average(): void
    {
        // 30 unités sorties sur une fenêtre de 4 jours, dont 2 jours sans sortie.
        $stats = DemandStatistics::fromDailyTotals([
            '2026-01-01' => 10.0,
            '2026-01-02' => 20.0,
        ], 4);

        $this->assertSame(7.5, $stats['avgDailyDemand']);
        $this->assertSame(30.0, $stats['totalIssued']);
        $this->assertSame(2, $stats['nonzeroDays']);
        $this->assertSame(4, $stats['sampleDays']);
    }

    public function test_standard_deviation_accounts_for_implicit_zero_days(): void
    {
        // Moyenne 7,5 ; somme des carrés = 162,5 (jours servis) + 112,5 (2 jours à zéro).
        $stats = DemandStatistics::fromDailyTotals([
            '2026-01-01' => 10.0,
            '2026-01-02' => 20.0,
        ], 4);

        $this->assertEqualsWithDelta(sqrt(275 / 3), $stats['demandStdDev'], 0.0005);
    }

    public function test_constant_demand_has_no_variability(): void
    {
        $stats = DemandStatistics::fromDailyTotals([
            '2026-01-01' => 5.0,
            '2026-01-02' => 5.0,
            '2026-01-03' => 5.0,
            '2026-01-04' => 5.0,
        ], 4);

        $this->assertSame(5.0, $stats['avgDailyDemand']);
        $this->assertSame(0.0, $stats['demandStdDev']);
    }

    public function test_single_day_window_cannot_estimate_variability(): void
    {
        $stats = DemandStatistics::fromDailyTotals(['2026-01-01' => 12.0], 1);

        $this->assertSame(12.0, $stats['avgDailyDemand']);
        $this->assertSame(0.0, $stats['demandStdDev']);
    }
}
