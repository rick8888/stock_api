<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TodayBriefService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TodayBriefTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);

        $this->manager = User::query()->create([
            'id' => 'usr-mgr',
            'first_name' => 'Sarah',
            'last_name' => 'Ops',
            'email' => 'sarah-today@test.local',
            'password' => 'demo',
            'role' => 'MANAGER',
            'title' => 'Ops',
            'avatar_hue' => 200,
            'warehouse_ids' => [],
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
            'id' => 'prd-1',
            'payload' => json_encode([
                'id' => 'prd-1',
                'name' => 'Huile',
                'sku' => 'HUI',
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
        ]);

        DB::table('stock_items')->insert([
            'id' => 'stk-1',
            'payload' => json_encode([
                'id' => 'stk-1',
                'productId' => 'prd-1',
                'warehouseId' => 'wh-a',
                'quantity' => 40,
                'reserved' => 0,
                'enabled' => true,
            ]),
            'product_id' => 'prd-1',
            'warehouse_id' => 'wh-a',
            'quantity' => 40,
            'reserved' => 0,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('alerts')->insert([
            'id' => 'al-1',
            'payload' => json_encode([
                'id' => 'al-1',
                'title' => 'Stock bas — Huile',
                'message' => 'Casa : 40 dispo.',
                'severity' => 'critical',
                'domain' => 'stock',
                'relatedId' => 'stk-1',
                'warehouseId' => 'wh-a',
                'roles' => ['MANAGER', 'WAREHOUSE_MANAGER'],
                'read' => false,
                'createdAt' => now()->toIso8601String(),
                'companyId' => 'co-test',
            ]),
            'title' => 'Stock bas — Huile',
            'message' => 'Casa : 40 dispo.',
            'severity' => 'critical',
            'domain' => 'stock',
            'related_id' => 'stk-1',
            'warehouse_id' => 'wh-a',
            'roles' => json_encode(['MANAGER', 'WAREHOUSE_MANAGER']),
            'read' => false,
            'alerted_at' => now(),
            'company_id' => 'co-test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_brief_returns_queue_from_alerts_and_stock_metrics(): void
    {
        $this->actingAs($this->manager, 'api');
        $brief = app(TodayBriefService::class)->brief();

        $this->assertSame(1, $brief['unreadAlerts']);
        $this->assertGreaterThanOrEqual(1, $brief['criticalAlerts']);
        $this->assertNotEmpty($brief['queue']);
        $this->assertSame('alert-al-1', $brief['queue'][0]['id']);
        $this->assertGreaterThanOrEqual(1, $brief['criticalStockCount']);
        $this->assertNotEmpty($brief['watchStock']);
    }

    public function test_ops_today_endpoint(): void
    {
        $this->actingAs($this->manager, 'api');
        $response = $this->getJson('/api/ops/today');
        $response->assertOk();
        $response->assertJsonPath('unreadAlerts', 1);
        $response->assertJsonPath('queue.0.id', 'alert-al-1');
    }
}
