<?php

namespace Tests\Feature;

use App\Models\StockLot;
use App\Models\StockPlacement;
use App\Models\WarehouseLocation;
use App\Repositories\Eloquent\StockLotRepository;
use App\Repositories\Eloquent\StockPlacementRepository;
use App\Repositories\Eloquent\WarehouseLocationRepository;
use App\Services\StockLotService;
use App\Services\StockPlacementService;
use App\Services\WarehouseLocationService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelationalWmsSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);
        $actor = User::query()->create([
            'id' => 'usr-wms',
            'first_name' => 'Wms',
            'last_name' => 'Ops',
            'email' => 'wms@test.local',
            'password' => 'demo',
            'role' => 'SUPER_ADMIN',
            'title' => 'Admin',
            'avatar_hue' => 10,
            'warehouse_ids' => [],
            'product_ids' => [],
            'region_ids' => [],
            'company_id' => 'co-wms',
        ]);
        $this->actingAs($actor, 'api');
        \Illuminate\Support\Facades\DB::table('warehouses')->insert([
            'id' => 'wh-wms',
            'name' => 'WMS',
            'city' => 'Casa',
            'company_id' => 'co-wms',
            'active' => 1,
            'payload' => json_encode(['id' => 'wh-wms', 'name' => 'WMS', 'city' => 'Casa', 'companyId' => 'co-wms', 'active' => true]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_location_lot_placement_dual_write_and_sql_lookup(): void
    {
        (new WarehouseLocation)->fillDocument([
            'id' => 'loc-1',
            'warehouseId' => 'wh-wms',
            'code' => 'A-01-01',
            'aisle' => 'A',
            'rack' => '01',
            'bin' => '01',
            'type' => 'pick',
            'active' => true,
            'companyId' => 'co-wms',
        ])->save();

        (new StockLot)->fillDocument([
            'id' => 'lot-1',
            'productId' => 'prd-1',
            'warehouseId' => 'wh-wms',
            'lotCode' => 'LOT-A',
            'expiryDate' => '2027-01-15',
            'locationId' => 'loc-1',
            'quantity' => 20,
            'companyId' => 'co-wms',
        ])->save();

        (new StockPlacement)->fillDocument([
            'id' => 'plc-1',
            'productId' => 'prd-1',
            'warehouseId' => 'wh-wms',
            'locationId' => 'loc-1',
            'lotId' => 'lot-1',
            'quantity' => 20,
            'companyId' => 'co-wms',
        ])->save();

        $this->assertDatabaseHas('warehouse_locations', [
            'id' => 'loc-1',
            'warehouse_id' => 'wh-wms',
            'code' => 'A-01-01',
            'aisle' => 'A',
        ]);
        $this->assertDatabaseHas('stock_lots', [
            'id' => 'lot-1',
            'lot_code' => 'LOT-A',
            'quantity' => 20,
        ]);
        $this->assertDatabaseHas('stock_placements', [
            'id' => 'plc-1',
            'location_id' => 'loc-1',
            'quantity' => 20,
        ]);

        $this->assertNotNull(app(WarehouseLocationRepository::class)->findByCode('wh-wms', 'A-01-01'));
        $this->assertNotNull(app(StockLotRepository::class)->findByCode('prd-1', 'wh-wms', 'LOT-A'));
        $this->assertNotNull(app(StockPlacementRepository::class)->findLine('prd-1', 'wh-wms', 'loc-1', 'lot-1'));
    }

    public function test_services_use_sql_scoped_lists(): void
    {
        app(WarehouseLocationService::class)->create([
            'warehouseId' => 'wh-wms',
            'aisle' => 'B',
            'rack' => '02',
            'bin' => '03',
            'type' => 'pick',
        ]);
        app(StockLotService::class)->receive('prd-2', 'wh-wms', 5, 'LOT-B', '2026-12-01');
        app(StockPlacementService::class)->putaway('prd-2', 'wh-wms', 5);

        $locations = app(WarehouseLocationService::class)->list('wh-wms');
        $lots = app(StockLotService::class)->list('wh-wms', 'prd-2');
        $placements = app(StockPlacementService::class)->list('wh-wms', 'prd-2');

        $this->assertTrue($locations->contains(fn (array $row) => ($row['code'] ?? '') === 'B-02-03'));
        $this->assertCount(1, $lots);
        $this->assertSame(5, (int) $lots->first()['quantity']);
        $this->assertTrue($placements->sum('quantity') >= 5);
    }

    public function test_legacy_payload_only_location_still_readable(): void
    {
        \Illuminate\Support\Facades\DB::table('warehouse_locations')->insert([
            'id' => 'loc-legacy',
            'payload' => json_encode([
                'id' => 'loc-legacy',
                'warehouseId' => 'wh-wms',
                'code' => 'Z-99-99',
                'aisle' => 'Z',
                'rack' => '99',
                'bin' => '99',
                'type' => 'bulk',
                'active' => true,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $found = app(WarehouseLocationRepository::class)->findByCode('wh-wms', 'Z-99-99');
        $this->assertNotNull($found);
        $this->assertSame('Z-99-99', $found['code']);
    }
}
