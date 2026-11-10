<?php

namespace Tests\Feature;

use App\Models\User;
use App\Repositories\Eloquent\DeliveryRepository;
use App\Repositories\Eloquent\RouteRepository;
use App\Repositories\Eloquent\ShipmentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelationalOutboundSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);
        $actor = User::query()->create([
            'id' => 'usr-out',
            'first_name' => 'Out',
            'last_name' => 'Bound',
            'email' => 'out@test.local',
            'password' => 'demo',
            'role' => 'SUPER_ADMIN',
            'title' => 'Admin',
            'avatar_hue' => 4,
            'warehouse_ids' => [],
            'product_ids' => [],
            'region_ids' => [],
            'company_id' => 'co-out',
        ]);
        $this->actingAs($actor, 'api');
    }

    public function test_delivery_route_shipment_relational_persist(): void
    {
        $route = app(RouteRepository::class)->put([
            'id' => 'rt-1',
            'name' => 'Casa Nord',
            'date' => '2026-11-10',
            'driverId' => 'drv-1',
            'vehicleId' => 'veh-1',
            'warehouseId' => 'wh-1',
            'status' => 'planned',
            'distanceKm' => 42.5,
            'progress' => 0,
            'companyId' => 'co-out',
        ]);
        $this->assertSame('2026-11-10', $route['date']);
        $this->assertDatabaseHas('routes', [
            'id' => 'rt-1',
            'warehouse_id' => 'wh-1',
            'distance_km' => 42.5,
        ]);
        $this->assertTrue(
            \Illuminate\Support\Facades\DB::table('routes')->where('id', 'rt-1')->whereDate('route_date', '2026-11-10')->exists(),
        );

        $delivery = app(DeliveryRepository::class)->put([
            'id' => 'dlv-1',
            'code' => 'BL-D-1',
            'client' => 'Client A',
            'address' => 'Rue 1',
            'city' => 'Casablanca',
            'scheduledAt' => '2026-11-10T09:00:00+01:00',
            'status' => 'prepared',
            'driverId' => 'drv-1',
            'vehicleId' => 'veh-1',
            'routeId' => 'rt-1',
            'sequence' => 1,
            'warehouseId' => 'wh-1',
            'progress' => 0,
            'companyId' => 'co-out',
            'location' => ['lat' => 33.5, 'lng' => -7.6, 'label' => 'Stop'],
            'lines' => [
                ['productId' => 'prd-1', 'productName' => 'A', 'quantity' => 4, 'unit' => 'u'],
            ],
        ]);
        $this->assertSame(4, (int) $delivery['lines'][0]['quantity']);
        $this->assertDatabaseHas('deliveries', [
            'id' => 'dlv-1',
            'warehouse_id' => 'wh-1',
            'route_id' => 'rt-1',
            'status' => 'prepared',
        ]);
        $this->assertDatabaseCount('delivery_lines', 1);

        $shipment = app(ShipmentRepository::class)->put([
            'id' => 'shp-1',
            'code' => 'TR-1',
            'originWarehouseId' => 'wh-1',
            'destination' => 'Rabat',
            'destinationWarehouseId' => 'wh-2',
            'vehicleId' => 'veh-1',
            'driverId' => 'drv-1',
            'date' => '2026-11-10T08:00:00+01:00',
            'status' => 'preparing',
            'progress' => 0,
            'shippingNoteIds' => ['bl-1', 'bl-2'],
            'companyId' => 'co-out',
            'origin' => ['lat' => 33.5, 'lng' => -7.6],
            'destinationLocation' => ['lat' => 34.0, 'lng' => -6.8],
            'lines' => [
                ['productId' => 'prd-1', 'productName' => 'A', 'quantity' => 10, 'unit' => 'u'],
            ],
        ]);
        $this->assertSame(['bl-1', 'bl-2'], $shipment['shippingNoteIds']);
        $this->assertDatabaseHas('shipments', [
            'id' => 'shp-1',
            'origin_warehouse_id' => 'wh-1',
            'destination_warehouse_id' => 'wh-2',
            'status' => 'preparing',
        ]);
        $this->assertDatabaseCount('shipment_lines', 1);

        $this->assertCount(1, app(DeliveryRepository::class)->forWarehouses(['wh-1']));
        $this->assertCount(1, app(RouteRepository::class)->forWarehouses(['wh-1']));
        $this->assertCount(1, app(ShipmentRepository::class)->forWarehouses(['wh-1']));
    }
}
