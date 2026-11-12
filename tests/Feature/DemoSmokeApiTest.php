<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Smoke démo : les endpoints du parcours client répondent 200 sous un compte ops.
 */
class DemoSmokeApiTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);

        Company::query()->create([
            'id' => 'co-demo',
            'name' => 'Demo Co',
            'legal_name' => 'Demo Co',
            'ice' => '',
            'city' => 'Casa',
            'email' => 'demo@test.local',
            'phone' => '',
            'status' => 'active',
            'users_count' => 1,
            'warehouses_count' => 1,
        ]);

        $this->manager = User::query()->create([
            'id' => 'usr-demo-mgr',
            'first_name' => 'Sarah',
            'last_name' => 'Demo',
            'email' => 'sarah-demo@test.local',
            'password' => 'demo',
            'role' => 'MANAGER',
            'title' => 'Ops',
            'avatar_hue' => 200,
            'warehouse_ids' => [],
            'product_ids' => [],
            'region_ids' => [],
            'company_id' => 'co-demo',
        ]);

        DB::table('warehouses')->insert([
            'id' => 'wh-demo',
            'payload' => json_encode([
                'id' => 'wh-demo',
                'name' => 'Casa Demo',
                'companyId' => 'co-demo',
                'active' => true,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_demo_journey_endpoints_respond(): void
    {
        $this->actingAs($this->manager, 'api');

        $this->getJson('/api/ops/today')->assertOk();
        $this->getJson('/api/notifications')->assertOk();
        $this->getJson('/api/replenishment/suggestions')->assertOk();
        $this->postJson('/api/replenishment/simulate', [
            'warehouseId' => 'wh-demo',
            'serviceLevel' => 0.95,
        ])->assertOk();
        $this->getJson('/api/procurement/board?section=orders&filter=open&page=1&pageSize=8')->assertOk();
        $this->getJson('/api/logistics/board?section=deliveries&filter=all&page=1&pageSize=8')->assertOk();
        $this->getJson('/api/analytics/snapshot')->assertOk();
    }
}
