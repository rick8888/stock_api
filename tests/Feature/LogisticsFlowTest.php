<?php

namespace Tests\Feature;

use App\Models\User;
use App\Repositories\Eloquent\DeliveryRepository;
use App\Repositories\Eloquent\RouteRepository;
use App\Repositories\Eloquent\ShippingNoteRepository;
use App\Repositories\Eloquent\StockItemRepository;
use App\Services\DeliveryService;
use App\Services\LogisticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LogisticsFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);

        $this->dispatcher = User::query()->create([
            'id' => 'usr-dispatch',
            'first_name' => 'Nadia',
            'last_name' => 'Dispatch',
            'email' => 'dispatch@test.local',
            'password' => 'demo',
            'role' => 'DISPATCHER',
            'title' => 'Dispatch',
            'avatar_hue' => 40,
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
                'city' => 'Casablanca',
                'companyId' => 'co-test',
                'active' => true,
                'lat' => 33.6095,
                'lng' => -7.5328,
            ]),
            'lat' => 33.6095,
            'lng' => -7.5328,
            'company_id' => 'co-test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('products')->insert([
            'id' => 'prd-huile',
            'payload' => json_encode([
                'id' => 'prd-huile',
                'name' => 'Huile',
                'sku' => 'HUI',
                'unit' => 'bidon',
                'price' => 10,
                'supplierId' => 'sup-1',
                'archived' => false,
                'dailyDemand' => 1,
            ]),
            'name' => 'Huile',
            'sku' => 'HUI',
            'unit' => 'bidon',
            'price' => 10,
            'supplier_id' => 'sup-1',
            'archived' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(StockItemRepository::class)->put([
            'id' => 'stk-1',
            'productId' => 'prd-huile',
            'warehouseId' => 'wh-a',
            'quantity' => 100,
            'reserved' => 0,
            'enabled' => true,
        ]);

        DB::table('vehicles')->insert([
            'id' => 'veh-1',
            'payload' => json_encode(['id' => 'veh-1', 'name' => 'Camion', 'plate' => 'A-1', 'status' => 'available', 'warehouseId' => 'wh-a', 'companyId' => 'co-test']),
            'name' => 'Camion',
            'plate' => 'A-1',
            'status' => 'available',
            'warehouse_id' => 'wh-a',
            'company_id' => 'co-test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('drivers')->insert([
            'id' => 'drv-1',
            'payload' => json_encode(['id' => 'drv-1', 'userId' => 'usr-dispatch', 'vehicleId' => 'veh-1', 'status' => 'available', 'warehouseId' => 'wh-a', 'companyId' => 'co-test']),
            'user_id' => 'usr-dispatch',
            'vehicle_id' => 'veh-1',
            'status' => 'available',
            'warehouse_id' => 'wh-a',
            'company_id' => 'co-test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_create_route_with_stops_creates_deliveries_and_binds_notes(): void
    {
        $this->actingAs($this->dispatcher, 'api');
        $note = $this->makeNote('bl-1', 'Market Test');

        $route = app(LogisticsService::class)->createRoute([
            'name' => 'Tournée A',
            'date' => now()->toDateString(),
            'driverId' => 'drv-1',
            'vehicleId' => 'veh-1',
            'warehouseId' => 'wh-a',
            'stops' => [[
                'shippingNoteId' => $note['id'],
                'client' => 'Market Test',
                'address' => 'Bd Test',
                'city' => 'Casablanca',
                'scheduledAt' => now()->addHour()->toIso8601String(),
            ]],
        ]);

        $this->assertSame('rt', substr($route['id'], 0, 2));
        $deliveries = app(DeliveryRepository::class)->forWarehouses(['wh-a'])
            ->filter(fn (array $item) => $item['routeId'] === $route['id']);
        $this->assertCount(1, $deliveries);
        $freshNote = app(ShippingNoteRepository::class)->find('bl-1');
        $this->assertSame('assigned', $freshNote['status']);
        $this->assertTrue((bool) $freshNote['stockReserved']);
        $this->assertGreaterThan(1, count($route['path'] ?? []));
    }

    public function test_preparation_cycle_ready_picking_loaded(): void
    {
        $this->actingAs($this->dispatcher, 'api');
        $this->makeNote('bl-prep', 'Client Prep');

        $picking = app(LogisticsService::class)->startPicking('bl-prep');
        $this->assertSame('picking', $picking['status']);

        $loaded = app(LogisticsService::class)->loadShippingNote('bl-prep', $this->dispatcher->id);
        $this->assertSame('loaded', $loaded['status']);
        $this->assertNotEmpty($loaded['loadedAt']);
        $this->assertFalse((bool) ($loaded['stockReserved'] ?? false));

        $stock = app(StockItemRepository::class)->all()
            ->first(fn (array $item) => $item['productId'] === 'prd-huile' && $item['warehouseId'] === 'wh-a');
        $this->assertSame(90, (int) $stock['quantity']);
    }

    public function test_pod_without_load_auto_consumes_reservation(): void
    {
        $this->actingAs($this->dispatcher, 'api');
        $note = $this->makeNote('bl-pod', 'Client POD');
        $route = app(RouteRepository::class)->put([
            'id' => 'rt-pod',
            'name' => 'POD',
            'date' => now()->toDateString(),
            'driverId' => 'drv-1',
            'vehicleId' => 'veh-1',
            'warehouseId' => 'wh-a',
            'status' => 'in_progress',
            'progress' => 10,
            'companyId' => 'co-test',
        ]);
        $delivery = app(DeliveryService::class)->create([
            'shippingNoteId' => $note['id'],
            'client' => 'Client POD',
            'address' => 'Rue POD',
            'city' => 'Casablanca',
            'scheduledAt' => now()->toIso8601String(),
            'routeId' => $route['id'],
            'sequence' => 1,
            'lines' => [],
        ]);

        $result = app(DeliveryService::class)->updateStatus($delivery['id'], 'delivered', null, 'Magasin', [
            'lat' => 33.57,
            'lng' => -7.62,
        ]);

        $this->assertSame('delivered', $result['status']);
        $fresh = app(ShippingNoteRepository::class)->find('bl-pod');
        $this->assertSame('delivered', $fresh['status']);
        $this->assertNotEmpty($fresh['loadedAt']);
        $stock = app(StockItemRepository::class)->all()
            ->first(fn (array $item) => $item['productId'] === 'prd-huile' && $item['warehouseId'] === 'wh-a');
        $this->assertSame(90, (int) $stock['quantity']);
    }

    public function test_failed_delivery_resets_note_to_ready(): void
    {
        $this->actingAs($this->dispatcher, 'api');
        $note = $this->makeNote('bl-fail', 'Client Fail');
        $route = app(RouteRepository::class)->put([
            'id' => 'rt-fail',
            'name' => 'Fail',
            'date' => now()->toDateString(),
            'driverId' => 'drv-1',
            'vehicleId' => 'veh-1',
            'warehouseId' => 'wh-a',
            'status' => 'in_progress',
            'progress' => 10,
            'companyId' => 'co-test',
        ]);
        $delivery = app(DeliveryService::class)->create([
            'shippingNoteId' => $note['id'],
            'client' => 'Client Fail',
            'address' => 'Rue Fail',
            'city' => 'Casablanca',
            'scheduledAt' => now()->toIso8601String(),
            'routeId' => $route['id'],
            'sequence' => 1,
            'lines' => [],
        ]);

        app(DeliveryService::class)->updateStatus($delivery['id'], 'failed', 'Absent');

        $fresh = app(ShippingNoteRepository::class)->find('bl-fail');
        $this->assertSame('ready', $fresh['status']);
        $this->assertNull($fresh['deliveryId'] ?? null);
        $this->assertFalse((bool) ($fresh['stockReserved'] ?? false));
    }

    public function test_update_route_removes_dropped_stops(): void
    {
        $this->actingAs($this->dispatcher, 'api');
        $noteA = $this->makeNote('bl-a', 'Client A');
        $noteB = $this->makeNote('bl-b', 'Client B');

        $route = app(LogisticsService::class)->createRoute([
            'name' => 'Double',
            'date' => now()->toDateString(),
            'driverId' => 'drv-1',
            'vehicleId' => 'veh-1',
            'warehouseId' => 'wh-a',
            'stops' => [
                [
                    'shippingNoteId' => $noteA['id'],
                    'client' => 'Client A',
                    'address' => 'A',
                    'city' => 'Casablanca',
                    'scheduledAt' => now()->toIso8601String(),
                ],
                [
                    'shippingNoteId' => $noteB['id'],
                    'client' => 'Client B',
                    'address' => 'B',
                    'city' => 'Casablanca',
                    'scheduledAt' => now()->addHour()->toIso8601String(),
                ],
            ],
        ]);

        $stops = app(DeliveryRepository::class)->forWarehouses(['wh-a'])
            ->filter(fn (array $item) => $item['routeId'] === $route['id'])
            ->values();
        $this->assertCount(2, $stops);

        app(LogisticsService::class)->updateRoute($route['id'], [
            'stops' => [[
                'id' => $stops[0]['id'],
                'shippingNoteId' => $noteA['id'],
                'client' => 'Client A',
                'address' => 'A',
                'city' => 'Casablanca',
                'scheduledAt' => now()->toIso8601String(),
            ]],
        ]);

        $remaining = app(DeliveryRepository::class)->forWarehouses(['wh-a'])
            ->filter(fn (array $item) => $item['routeId'] === $route['id']);
        $this->assertCount(1, $remaining);
        $this->assertSame('ready', app(ShippingNoteRepository::class)->find('bl-b')['status']);
    }

    /** @return array<string, mixed> */
    private function makeNote(string $id, string $client): array
    {
        return app(ShippingNoteRepository::class)->put([
            'id' => $id,
            'code' => 'BL-'.$id,
            'warehouseId' => 'wh-a',
            'client' => $client,
            'address' => 'Adresse '.$client,
            'city' => 'Casablanca',
            'status' => 'ready',
            'lines' => [[
                'productId' => 'prd-huile',
                'productName' => 'Huile',
                'sku' => 'HUI',
                'quantity' => 10,
                'unit' => 'bidon',
            ]],
            'companyId' => 'co-test',
            'stockReserved' => false,
            'createdAt' => now()->toIso8601String(),
        ]);
    }
}
