<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockOverviewTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);

        $this->manager = User::query()->create([
            'id' => 'usr-stock',
            'first_name' => 'Sara',
            'last_name' => 'Stock',
            'email' => 'stock-ov@test.local',
            'password' => 'demo',
            'role' => 'MANAGER',
            'title' => 'Ops',
            'avatar_hue' => 20,
            'warehouse_ids' => ['wh-a'],
            'product_ids' => [],
            'region_ids' => [],
            'company_id' => 'co-test',
        ]);

        DB::table('warehouses')->insert([
            'id' => 'wh-a',
            'payload' => json_encode([
                'id' => 'wh-a',
                'name' => 'Casa',
                'companyId' => 'co-test',
                'active' => true,
            ]),
            'company_id' => 'co-test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('products')->insert([
            [
                'id' => 'prd-crit',
                'payload' => json_encode([
                    'id' => 'prd-crit',
                    'name' => 'Huile',
                    'sku' => 'HUI',
                    'unit' => 'bidon',
                    'minStock' => 50,
                    'dailyDemand' => 20,
                    'archived' => false,
                    'companyId' => 'co-test',
                ]),
                'name' => 'Huile',
                'sku' => 'HUI',
                'archived' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 'prd-ok',
                'payload' => json_encode([
                    'id' => 'prd-ok',
                    'name' => 'Farine',
                    'sku' => 'FAR',
                    'unit' => 'sac',
                    'minStock' => 10,
                    'dailyDemand' => 1,
                    'archived' => false,
                    'companyId' => 'co-test',
                ]),
                'name' => 'Farine',
                'sku' => 'FAR',
                'archived' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('stock_items')->insert([
            [
                'id' => 'stk-crit',
                'payload' => json_encode([
                    'id' => 'stk-crit',
                    'productId' => 'prd-crit',
                    'warehouseId' => 'wh-a',
                    'quantity' => 40,
                    'reserved' => 0,
                    'enabled' => true,
                ]),
                'product_id' => 'prd-crit',
                'warehouse_id' => 'wh-a',
                'quantity' => 40,
                'reserved' => 0,
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 'stk-ok',
                'payload' => json_encode([
                    'id' => 'stk-ok',
                    'productId' => 'prd-ok',
                    'warehouseId' => 'wh-a',
                    'quantity' => 200,
                    'reserved' => 0,
                    'enabled' => true,
                ]),
                'product_id' => 'prd-ok',
                'warehouse_id' => 'wh-a',
                'quantity' => 200,
                'reserved' => 0,
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function test_overview_computes_health_counts_and_recommendation(): void
    {
        $this->actingAs($this->manager, 'api');
        $response = $this->getJson('/api/stock/overview');
        $response->assertOk();
        $response->assertJsonPath('counts.all', 2);
        $this->assertGreaterThanOrEqual(1, $response->json('counts.critical'));

        $crit = collect($response->json('rows'))->firstWhere('productId', 'prd-crit');
        $this->assertNotNull($crit);
        $this->assertSame('critical', $crit['status']);
        $this->assertSame(40, $crit['available']);
        $this->assertEquals(2, $crit['days']);
        $this->assertArrayHasKey('recommendation', $crit);
        $this->assertGreaterThan(0, $crit['recommendation']['quantity']);
    }
}
