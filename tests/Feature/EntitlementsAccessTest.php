<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntitlementsAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);

        Company::query()->create([
            'id' => 'co-ent',
            'name' => 'Ent Co',
            'legal_name' => 'Ent Co',
            'ice' => '',
            'city' => 'Casa',
            'email' => 'ent@test.local',
            'phone' => '',
            'status' => 'active',
            'users_count' => 1,
            'warehouses_count' => 1,
        ]);
    }

    public function test_driver_can_read_own_entitlements(): void
    {
        $driver = User::query()->create([
            'id' => 'usr-drv-ent',
            'first_name' => 'Karim',
            'last_name' => 'Route',
            'email' => 'driver-ent@test.local',
            'password' => 'demo',
            'role' => 'DRIVER',
            'title' => 'Chauffeur',
            'avatar_hue' => 20,
            'warehouse_ids' => ['wh-a'],
            'product_ids' => [],
            'region_ids' => [],
            'company_id' => 'co-ent',
        ]);

        $this->actingAs($driver, 'api');
        $response = $this->getJson('/api/platform/entitlements');
        $response->assertOk();
        $response->assertJsonPath('companyId', 'co-ent');
        $this->assertIsArray($response->json('modules'));
    }

    public function test_finance_can_read_own_entitlements(): void
    {
        $finance = User::query()->create([
            'id' => 'usr-fin-ent',
            'first_name' => 'Lina',
            'last_name' => 'Compta',
            'email' => 'finance-ent@test.local',
            'password' => 'demo',
            'role' => 'FINANCE',
            'title' => 'Finance',
            'avatar_hue' => 50,
            'warehouse_ids' => [],
            'product_ids' => [],
            'region_ids' => [],
            'company_id' => 'co-ent',
        ]);

        $this->actingAs($finance, 'api');
        $this->getJson('/api/platform/entitlements')->assertOk();
    }
}
