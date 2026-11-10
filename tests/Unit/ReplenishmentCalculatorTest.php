<?php

namespace Tests\Unit;

use App\Services\Replenishment\ReplenishmentCalculator;
use PHPUnit\Framework\TestCase;

class ReplenishmentCalculatorTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function evaluate(array $overrides = []): array
    {
        return ReplenishmentCalculator::evaluate([
            'avgDailyDemand' => 10.0,
            'demandStdDev' => 0.0,
            'sampleDays' => 90,
            'leadTimeDays' => 5.0,
            'leadTimeStdDev' => 0.0,
            'serviceLevel' => 0.95,
            'minStock' => 0,
            'available' => 20,
            'inTransit' => 0,
            'pendingNeed' => 0,
            'unit' => 'u',
            ...$overrides,
        ]);
    }

    public function test_reorder_point_covers_demand_during_lead_time(): void
    {
        $result = $this->evaluate();

        // 10/j × 5 j de délai, sans aléa donc sans stock de sécurité.
        $this->assertSame(0, $result['safetyStock']);
        $this->assertSame(50, $result['reorderPoint']);
        // Cible = point de commande + 7 jours de consommation (périodicité par défaut).
        $this->assertSame(120, $result['targetLevel']);
        $this->assertSame(100, $result['suggestedQuantity']);
    }

    public function test_safety_stock_scales_with_demand_variability(): void
    {
        // écart-type sur délai = sqrt(9 × 4²) = 12 ; z(95 %) ≈ 1,6449 → 20 arrondi au supérieur.
        $result = $this->evaluate([
            'demandStdDev' => 4.0,
            'leadTimeDays' => 9.0,
        ]);

        $this->assertSame(20, $result['safetyStock']);
        $this->assertSame(110, $result['reorderPoint']);
    }

    public function test_safety_stock_scales_with_lead_time_variability(): void
    {
        // écart-type sur délai = sqrt(10² × 2²) = 20 ; z(95 %) × 20 ≈ 32,9 → 33.
        $result = $this->evaluate(['leadTimeStdDev' => 2.0]);

        $this->assertSame(33, $result['safetyStock']);
        $this->assertSame(83, $result['reorderPoint']);
    }

    public function test_higher_service_level_raises_safety_stock(): void
    {
        $base = $this->evaluate(['demandStdDev' => 4.0, 'serviceLevel' => 0.90]);
        $strict = $this->evaluate(['demandStdDev' => 4.0, 'serviceLevel' => 0.99]);

        $this->assertGreaterThan($base['safetyStock'], $strict['safetyStock']);
    }

    public function test_no_suggestion_when_stock_position_is_above_reorder_point(): void
    {
        $result = $this->evaluate(['available' => 200]);

        $this->assertFalse($result['shouldOrder']);
        $this->assertSame(0, $result['suggestedQuantity']);
    }

    public function test_in_transit_counts_as_coverage(): void
    {
        $result = $this->evaluate(['available' => 20, 'inTransit' => 100]);

        $this->assertSame(120, $result['stockPosition']);
        $this->assertFalse($result['shouldOrder']);
        $this->assertSame(0, $result['suggestedQuantity']);
    }

    public function test_pending_requests_consume_the_stock_position(): void
    {
        $result = $this->evaluate(['available' => 200, 'pendingNeed' => 180]);

        $this->assertTrue($result['shouldOrder']);
        $this->assertSame(100, $result['suggestedQuantity']);
    }

    public function test_quantity_is_raised_to_supplier_minimum_order(): void
    {
        $result = $this->evaluate([
            'avgDailyDemand' => 1.0,
            'leadTimeDays' => 2.0,
            'available' => 0,
            'minOrderQty' => 50,
        ]);

        $this->assertSame(9, $result['rawQuantity']);
        $this->assertSame(50, $result['suggestedQuantity']);
        $this->assertStringContainsString('commande minimum', implode(' ', $result['reasons']));
    }

    public function test_quantity_is_rounded_up_to_the_order_multiple(): void
    {
        $result = $this->evaluate([
            'avgDailyDemand' => 1.0,
            'leadTimeDays' => 2.0,
            'available' => 0,
            'orderMultiple' => 24,
        ]);

        $this->assertSame(24, $result['suggestedQuantity']);
        $this->assertStringContainsString('conditionnement', implode(' ', $result['reasons']));
    }

    public function test_minimum_order_is_applied_before_the_multiple(): void
    {
        $result = $this->evaluate([
            'avgDailyDemand' => 1.0,
            'leadTimeDays' => 2.0,
            'available' => 0,
            'minOrderQty' => 50,
            'orderMultiple' => 24,
        ]);

        // 9 → 50 (minimum) → 72 (multiple de 24 immédiatement supérieur).
        $this->assertSame(72, $result['suggestedQuantity']);
    }

    public function test_max_stock_caps_the_target_level(): void
    {
        $result = $this->evaluate(['maxStock' => 80]);

        $this->assertSame(80, $result['targetLevel']);
        $this->assertSame(60, $result['suggestedQuantity']);
    }

    public function test_supplier_constraints_exceeding_max_stock_are_flagged(): void
    {
        $result = $this->evaluate([
            'avgDailyDemand' => 1.0,
            'leadTimeDays' => 2.0,
            'available' => 0,
            'maxStock' => 20,
            'minOrderQty' => 100,
        ]);

        $this->assertSame(100, $result['suggestedQuantity']);
        $this->assertStringContainsString('au-delà du maximum', implode(' ', $result['reasons']));
    }

    public function test_manual_threshold_acts_as_a_floor_on_the_reorder_point(): void
    {
        $result = $this->evaluate([
            'avgDailyDemand' => 1.0,
            'leadTimeDays' => 2.0,
            'minStock' => 100,
            'available' => 0,
        ]);

        $this->assertSame(100, $result['reorderPoint']);
        $this->assertSame(100, $result['suggestedQuantity']);
        $this->assertStringContainsString('plancher', implode(' ', $result['reasons']));
    }

    public function test_manual_safety_stock_overrides_the_computed_one(): void
    {
        $result = $this->evaluate([
            'demandStdDev' => 4.0,
            'safetyStockOverride' => 15,
        ]);

        $this->assertSame(15, $result['safetyStock']);
        $this->assertSame(65, $result['reorderPoint']);
        $this->assertStringContainsString('imposé manuellement', implode(' ', $result['reasons']));
    }

    public function test_min_max_policy_uses_declared_thresholds(): void
    {
        $result = $this->evaluate([
            'policy' => ReplenishmentCalculator::POLICY_MIN_MAX,
            'minStock' => 30,
            'maxStock' => 90,
            'available' => 10,
        ]);

        $this->assertSame(30, $result['reorderPoint']);
        $this->assertSame(90, $result['targetLevel']);
        $this->assertSame(30, $result['safetyStock']);
        $this->assertSame(80, $result['suggestedQuantity']);
    }

    public function test_product_without_history_falls_back_to_manual_threshold(): void
    {
        $result = $this->evaluate([
            'avgDailyDemand' => 0.0,
            'sampleDays' => 0,
            'minStock' => 20,
            'available' => 5,
        ]);

        $this->assertSame(20, $result['reorderPoint']);
        $this->assertSame(15, $result['suggestedQuantity']);
        $this->assertNull($result['coverDays']);
        $this->assertStringContainsString('Aucune sortie observée', implode(' ', $result['reasons']));
    }

    public function test_short_history_is_reported_as_unreliable(): void
    {
        $result = $this->evaluate(['sampleDays' => 5]);

        $this->assertStringContainsString('Historique court', implode(' ', $result['reasons']));
    }

    public function test_stockout_is_high_risk(): void
    {
        $this->assertSame('high', $this->evaluate(['available' => 0])['risk']);
    }

    public function test_coverage_shorter_than_lead_time_is_high_risk(): void
    {
        // 30 unités disponibles pour 10/j, soit 3 jours : la commande arriverait trop tard.
        $result = $this->evaluate(['available' => 30, 'leadTimeDays' => 5.0]);

        $this->assertSame(3.0, $result['coverDays']);
        $this->assertSame('high', $result['risk']);
    }

    public function test_comfortable_stock_is_low_risk(): void
    {
        $result = $this->evaluate(['available' => 500]);

        $this->assertSame('low', $result['risk']);
        $this->assertSame(0, $result['suggestedQuantity']);
    }
}
