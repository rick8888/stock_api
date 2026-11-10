<?php

namespace Tests\Feature;

use App\Models\User;
use App\Repositories\Eloquent\PurchaseOrderRepository;
use App\Repositories\Eloquent\SupplierProductRepository;
use App\Repositories\Eloquent\SupplierWarehouseTermRepository;
use App\Services\Replenishment\DemandForecastService;
use App\Services\Replenishment\ReplenishmentService;
use App\Support\Uid;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReplenishmentEngineTest extends TestCase
{
    use RefreshDatabase;

    private const HISTORY_DAYS = 30;

    private const DAILY_ISSUE = 10;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);

        $this->actor = User::query()->create([
            'id' => 'usr-buyer',
            'first_name' => 'Amine',
            'last_name' => 'Achat',
            'email' => 'achat@test.local',
            'password' => 'demo',
            'role' => 'WAREHOUSE_MANAGER',
            'title' => 'Approvisionneur',
            'avatar_hue' => 20,
            'warehouse_ids' => ['wh-a'],
            'product_ids' => [],
            'region_ids' => [],
            'company_id' => 'co-test',
        ]);
        $this->actingAs($this->actor, 'api');

        $this->insertDocument('warehouses', 'wh-a', [
            'name' => 'Entrepôt Casablanca',
            'city' => 'Casablanca',
            'companyId' => 'co-test',
            'active' => true,
        ]);

        $this->insertDocument('suppliers', 'sup-1', [
            'name' => 'Atlas Distribution',
            'leadTimeDays' => 5,
            'status' => 'active',
            'companyId' => 'co-test',
        ]);

        $this->insertDocument('products', 'prd-1', [
            'sku' => 'SKU-1',
            'name' => 'Gants nitrile',
            'unit' => 'boîte',
            'minStock' => 0,
            'price' => 12.5,
            'supplierId' => 'sup-1',
            'archived' => false,
            'companyId' => 'co-test',
        ]);

        $this->insertDocument('stock_items', 'stk-1', [
            'productId' => 'prd-1',
            'warehouseId' => 'wh-a',
            'quantity' => 20,
            'reserved' => 0,
            'enabled' => true,
        ], [
            'product_id' => 'prd-1',
            'warehouse_id' => 'wh-a',
            'quantity' => 20,
            'reserved' => 0,
            'enabled' => true,
        ]);

        // Consommation parfaitement régulière : la demande moyenne est donc exactement
        // DAILY_ISSUE et sa variabilité nulle, ce qui rend les attentes déterministes.
        $today = CarbonImmutable::today();
        for ($i = 0; $i < self::HISTORY_DAYS; $i++) {
            $movedAt = $today->subDays($i)->setTime(9, 0);
            $this->insertDocument('stock_movements', 'mov-'.$i, [
                'productId' => 'prd-1',
                'type' => 'issue',
                'quantity' => self::DAILY_ISSUE,
                'fromWarehouseId' => 'wh-a',
                'userId' => 'usr-buyer',
                'reference' => 'BL-'.$i,
            ], [
                'product_id' => 'prd-1',
                'type' => 'issue',
                'quantity' => self::DAILY_ISSUE,
                'from_warehouse_id' => 'wh-a',
                'user_id' => 'usr-buyer',
                'moved_at' => $movedAt,
            ]);
        }

        app(SupplierProductRepository::class)->upsert([
            'supplier_id' => 'sup-1',
            'product_id' => 'prd-1',
            'unit_price' => 12.5,
            'min_order_qty' => 50,
            'order_multiple' => 30,
            'lead_time_days' => null,
            'active' => true,
            'company_id' => 'co-test',
        ]);
    }

    public function test_demand_is_derived_from_stock_movements(): void
    {
        $stats = app(DemandForecastService::class)->recompute(['wh-a']);

        $this->assertCount(1, $stats);
        $row = $stats->first();

        $this->assertSame('prd-1', $row['productId']);
        $this->assertSame('wh-a', $row['warehouseId']);
        $this->assertSame((float) self::DAILY_ISSUE, $row['avgDailyDemand']);
        $this->assertSame(0.0, $row['demandStdDev']);
        $this->assertSame(self::HISTORY_DAYS, $row['sampleDays']);
        $this->assertSame(self::HISTORY_DAYS * self::DAILY_ISSUE, $row['totalIssued']);
    }

    public function test_suggestion_combines_demand_lead_time_and_supplier_constraints(): void
    {
        $suggestions = $this->computeSuggestions();

        $this->assertCount(1, $suggestions);
        $row = $suggestions->first();

        // 10/j × 5 j de délai, sans aléa : point de commande 50, cible 50 + 7 j = 120.
        $this->assertSame(50, $row['reorderPoint']);
        $this->assertSame(120, $row['targetLevel']);
        $this->assertSame(0, $row['safetyStock']);
        // Besoin brut 100, relevé à 120 par le conditionnement de 30.
        $this->assertSame(100, $row['rawQuantity']);
        $this->assertSame(120, $row['suggestedQuantity']);
        $this->assertSame(1500.0, $row['estimatedCost']);
        $this->assertSame('sup-1', $row['supplierId']);
    }

    public function test_warehouse_specific_lead_time_overrides_the_supplier_default(): void
    {
        app(SupplierWarehouseTermRepository::class)->upsert([
            'supplier_id' => 'sup-1',
            'warehouse_id' => 'wh-a',
            'lead_time_days' => 10,
            'active' => true,
            'company_id' => 'co-test',
        ]);

        $row = $this->computeSuggestions()->first();

        // Le délai passe de 5 à 10 jours : le point de commande double.
        $this->assertSame(100, $row['reorderPoint']);
        $this->assertSame(170, $row['targetLevel']);
        $this->assertSame(150, $row['suggestedQuantity']);
    }

    public function test_safety_stock_follows_irregular_supplier_lead_time(): void
    {
        app(SupplierWarehouseTermRepository::class)->upsert([
            'supplier_id' => 'sup-1',
            'warehouse_id' => 'wh-a',
            'lead_time_days' => 5,
            'lead_time_std_dev' => 2,
            'active' => true,
            'company_id' => 'co-test',
        ]);

        $row = $this->computeSuggestions()->first();

        // écart-type sur délai = sqrt(10² × 2²) = 20 ; z(95 %) × 20 ≈ 32,9 → 33.
        $this->assertSame(33, $row['safetyStock']);
        $this->assertSame(83, $row['reorderPoint']);
    }

    public function test_order_arriving_within_the_horizon_cancels_the_need(): void
    {
        $this->placeOrder(quantity: 200, expectedInDays: 3);

        $this->assertCount(0, $this->computeSuggestions());
    }

    public function test_order_arriving_beyond_the_horizon_is_not_deducted(): void
    {
        $this->placeOrder(quantity: 500, expectedInDays: 60);

        $row = $this->computeSuggestions()->first();

        $this->assertSame(0, $row['inTransit']);
        $this->assertSame(120, $row['suggestedQuantity']);
        $this->assertStringContainsString('hors horizon', implode(' ', $row['reasons']));
    }

    public function test_suggestions_are_persisted_and_replaced_on_each_run(): void
    {
        $this->computeSuggestions();
        $this->computeSuggestions();

        $this->assertSame(1, DB::table('replenishment_suggestions')->count());
    }

    public function test_endpoint_exposes_the_computed_suggestions(): void
    {
        app(DemandForecastService::class)->recompute(['wh-a']);

        $this->postJson('/api/replenishment/compute', ['warehouseId' => 'wh-a'])
            ->assertOk()
            ->assertJsonPath('0.productId', 'prd-1')
            ->assertJsonPath('0.suggestedQuantity', 120);

        $this->getJson('/api/replenishment/suggestions?warehouseId=wh-a')
            ->assertOk()
            ->assertJsonPath('0.reorderPoint', 50);
    }

    public function test_warehouse_outside_the_user_scope_is_rejected(): void
    {
        $this->insertDocument('warehouses', 'wh-z', [
            'name' => 'Entrepôt tiers',
            'companyId' => 'co-other',
            'active' => true,
        ]);

        $this->postJson('/api/replenishment/compute', ['warehouseId' => 'wh-z'])
            ->assertForbidden();
    }

    private function computeSuggestions()
    {
        app(DemandForecastService::class)->recompute(['wh-a']);

        return app(ReplenishmentService::class)->compute('wh-a');
    }

    private function placeOrder(int $quantity, int $expectedInDays): void
    {
        app(PurchaseOrderRepository::class)->put([
            'id' => Uid::make('po'),
            'code' => 'PO-TEST',
            'supplierId' => 'sup-1',
            'warehouseId' => 'wh-a',
            'status' => 'sent',
            'expectedDate' => CarbonImmutable::today()->addDays($expectedInDays)->toDateString(),
            'createdAt' => CarbonImmutable::today()->toIso8601String(),
            'companyId' => 'co-test',
            'items' => [
                ['productId' => 'prd-1', 'quantity' => $quantity],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $columns
     */
    private function insertDocument(string $table, string $id, array $payload, array $columns = []): void
    {
        DB::table($table)->insert([
            'id' => $id,
            'payload' => json_encode(['id' => $id, ...$payload]),
            'created_at' => now(),
            'updated_at' => now(),
            ...$columns,
        ]);
    }
}
