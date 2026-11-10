<?php

namespace Tests\Feature;

use App\Models\User;
use App\Repositories\Eloquent\InventorySessionRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelationalInventorySchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);
        $actor = User::query()->create([
            'id' => 'usr-inv',
            'first_name' => 'Inv',
            'last_name' => 'Rel',
            'email' => 'invrel@test.local',
            'password' => 'demo',
            'role' => 'SUPER_ADMIN',
            'title' => 'Admin',
            'avatar_hue' => 1,
            'warehouse_ids' => [],
            'product_ids' => [],
            'region_ids' => [],
            'company_id' => 'co-inv',
        ]);
        $this->actingAs($actor, 'api');
    }

    public function test_inventory_session_persists_header_and_lines(): void
    {
        $session = app(InventorySessionRepository::class)->put([
            'id' => 'inv-1',
            'warehouseId' => 'wh-1',
            'status' => 'in_progress',
            'mode' => 'partial',
            'scope' => ['locationIds' => ['loc-1']],
            'startedAt' => '2026-11-10T09:00:00+01:00',
            'userId' => 'usr-inv',
            'companyId' => 'co-inv',
            'counted' => 8,
            'expected' => 10,
            'lines' => [
                ['productId' => 'prd-1', 'expected' => 6, 'counted' => 5],
                ['productId' => 'prd-2', 'expected' => 4, 'counted' => 3],
            ],
        ]);

        $this->assertSame('partial', $session['mode']);
        $this->assertCount(2, $session['lines']);
        $this->assertDatabaseHas('inventory_sessions', [
            'id' => 'inv-1',
            'warehouse_id' => 'wh-1',
            'status' => 'in_progress',
            'mode' => 'partial',
            'company_id' => 'co-inv',
        ]);
        $this->assertDatabaseCount('inventory_session_lines', 2);
        $this->assertNotNull(app(InventorySessionRepository::class)->openForWarehouse('wh-1'));
        $this->assertCount(1, app(InventorySessionRepository::class)->forWarehouses(['wh-1']));
    }
}
