<?php

namespace Tests\Feature;

use App\Models\User;
use App\Repositories\Eloquent\DeliveryRepository;
use App\Repositories\Eloquent\RouteRepository;
use App\Repositories\Eloquent\VehicleRepository;
use App\Services\Logistics\TrackingService;
use App\Support\Geo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TrackingEngineTest extends TestCase
{
    use RefreshDatabase;

    private User $dispatcher;

    private User $driver;

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

        $this->driver = User::query()->create([
            'id' => 'usr-driver',
            'first_name' => 'Mehdi',
            'last_name' => 'Route',
            'email' => 'driver@test.local',
            'password' => 'demo',
            'role' => 'DRIVER',
            'title' => 'Chauffeur',
            'avatar_hue' => 80,
            'warehouse_ids' => ['wh-a'],
            'product_ids' => [],
            'region_ids' => [],
            'company_id' => 'co-test',
        ]);

        $this->insertDocument('warehouses', 'wh-a', [
            'name' => 'Casablanca',
            'city' => 'Casablanca',
            'companyId' => 'co-test',
            'active' => true,
            'lat' => 33.6095,
            'lng' => -7.5328,
        ], ['lat' => 33.6095, 'lng' => -7.5328, 'company_id' => 'co-test']);

        app(VehicleRepository::class)->put([
            'id' => 'veh-1',
            'name' => 'Camion 1',
            'plate' => 'A-100',
            'status' => 'available',
            'warehouseId' => 'wh-a',
            'companyId' => 'co-test',
        ]);

        DB::table('drivers')->insert([
            'id' => 'drv-1',
            'payload' => json_encode([
                'id' => 'drv-1',
                'userId' => 'usr-driver',
                'vehicleId' => 'veh-1',
                'status' => 'on_route',
                'warehouseId' => 'wh-a',
                'companyId' => 'co-test',
            ]),
            'user_id' => 'usr-driver',
            'vehicle_id' => 'veh-1',
            'status' => 'on_route',
            'warehouse_id' => 'wh-a',
            'company_id' => 'co-test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $origin = ['lat' => 33.6095, 'lng' => -7.5328, 'label' => 'Entrepôt'];
        $stop = ['lat' => 33.5712, 'lng' => -7.6498, 'label' => 'Market Beauséjour'];
        $mid = Geo::pointAlong([$origin, $stop], 0.4);

        app(RouteRepository::class)->put([
            'id' => 'rt-1',
            'name' => 'Tournée test',
            'date' => now()->toDateString(),
            'driverId' => 'drv-1',
            'vehicleId' => 'veh-1',
            'warehouseId' => 'wh-a',
            'status' => 'in_progress',
            'distanceKm' => Geo::haversineKm($origin, $stop),
            'progress' => 0,
            'path' => [$origin, $mid, $stop],
            'companyId' => 'co-test',
        ]);

        app(DeliveryRepository::class)->put([
            'id' => 'dlv-1',
            'client' => 'Market Beauséjour',
            'address' => 'Bd Beauséjour',
            'city' => 'Casablanca',
            'scheduledAt' => now()->addHours(2)->toIso8601String(),
            'status' => 'prepared',
            'driverId' => 'drv-1',
            'vehicleId' => 'veh-1',
            'routeId' => 'rt-1',
            'sequence' => 1,
            'warehouseId' => 'wh-a',
            'location' => $stop,
            'path' => [$origin, $stop],
            'currentPosition' => $origin,
            'progress' => 0,
            'lines' => [],
            'companyId' => 'co-test',
        ]);
    }

    public function test_a_driver_ping_updates_route_progress_and_eta(): void
    {
        $this->actingAs($this->driver, 'api');

        $origin = ['lat' => 33.6095, 'lng' => -7.5328];
        $stop = ['lat' => 33.5712, 'lng' => -7.6498];
        $mid = Geo::pointAlong([$origin, $stop], 0.4);

        $result = app(TrackingService::class)->ping([
            'routeId' => 'rt-1',
            'lat' => $mid['lat'],
            'lng' => $mid['lng'],
            'speedKmh' => 28,
            'source' => 'driver_app',
        ]);

        $this->assertSame('rt-1', $result['route']['id']);
        $this->assertGreaterThan(30, $result['route']['progress']);
        $this->assertLessThan(60, $result['route']['progress']);
        $this->assertNotEmpty($result['etaAt']);
        $this->assertSame(1, DB::table('tracking_events')->count());
        $this->assertDatabaseHas('vehicles', [
            'id' => 'veh-1',
            'status' => 'on_mission',
        ]);
    }

    public function test_the_active_stop_becomes_in_transit(): void
    {
        $this->actingAs($this->driver, 'api');

        $origin = ['lat' => 33.6095, 'lng' => -7.5328];
        $stop = ['lat' => 33.5712, 'lng' => -7.6498];
        $mid = Geo::pointAlong([$origin, $stop], 0.5);

        app(TrackingService::class)->ping([
            'routeId' => 'rt-1',
            'lat' => $mid['lat'],
            'lng' => $mid['lng'],
        ]);

        $delivery = app(DeliveryRepository::class)->find('dlv-1');
        $this->assertSame('in_transit', $delivery['status']);
        $this->assertGreaterThan(0, $delivery['progress']);
        $this->assertNotEmpty($delivery['eta']);
    }

    public function test_a_foreign_driver_cannot_ping_the_route(): void
    {
        $stranger = User::query()->create([
            'id' => 'usr-other',
            'first_name' => 'Autre',
            'last_name' => 'Chauffeur',
            'email' => 'other@test.local',
            'password' => 'demo',
            'role' => 'DRIVER',
            'title' => 'Chauffeur',
            'avatar_hue' => 10,
            'warehouse_ids' => ['wh-a'],
            'product_ids' => [],
            'region_ids' => [],
            'company_id' => 'co-test',
        ]);
        DB::table('drivers')->insert([
            'id' => 'drv-2',
            'payload' => json_encode(['id' => 'drv-2', 'userId' => 'usr-other', 'warehouseId' => 'wh-a', 'companyId' => 'co-test']),
            'user_id' => 'usr-other',
            'status' => 'available',
            'warehouse_id' => 'wh-a',
            'company_id' => 'co-test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($stranger, 'api');

        $this->postJson('/api/tracking/ping', [
            'routeId' => 'rt-1',
            'lat' => 33.58,
            'lng' => -7.58,
        ])->assertForbidden();
    }

    public function test_dispatcher_can_read_live_and_route_snapshot(): void
    {
        $this->actingAs($this->driver, 'api');
        app(TrackingService::class)->ping([
            'routeId' => 'rt-1',
            'lat' => 33.59,
            'lng' => -7.58,
        ]);

        $this->actingAs($this->dispatcher, 'api');

        $this->getJson('/api/tracking/live')
            ->assertOk()
            ->assertJsonPath('0.routeId', 'rt-1');

        $this->getJson('/api/tracking/routes/rt-1')
            ->assertOk()
            ->assertJsonPath('routeId', 'rt-1')
            ->assertJsonPath('events.0.routeId', 'rt-1');
    }

    public function test_endpoint_rejects_invalid_coordinates(): void
    {
        $this->actingAs($this->driver, 'api');

        $this->postJson('/api/tracking/ping', [
            'routeId' => 'rt-1',
            'lat' => 200,
            'lng' => -7.58,
        ])->assertStatus(422);
    }

    /** @param  array<string, mixed>  $payload
     *  @param  array<string, mixed>  $columns */
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
