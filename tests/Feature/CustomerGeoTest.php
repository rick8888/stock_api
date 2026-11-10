<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ShippingNote;
use App\Models\User;
use App\Services\DeliveryService;
use Database\Seeders\CustomerGeoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerGeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);

        User::query()->create([
            'id' => 'usr-admin',
            'first_name' => 'Admin',
            'last_name' => 'Test',
            'email' => 'admin@test.local',
            'password' => 'demo',
            'role' => 'ADMIN',
            'title' => 'Admin',
            'avatar_hue' => 10,
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

        DB::table('routes')->insert([
            'id' => 'rt-geo',
            'payload' => json_encode([
                'id' => 'rt-geo',
                'name' => 'Geo',
                'date' => now()->toDateString(),
                'driverId' => 'drv-1',
                'vehicleId' => 'veh-1',
                'warehouseId' => 'wh-a',
                'status' => 'planned',
                'progress' => 0,
                'companyId' => 'co-test',
            ]),
            'name' => 'Geo',
            'route_date' => now()->toDateString(),
            'driver_id' => 'drv-1',
            'vehicle_id' => 'veh-1',
            'warehouse_id' => 'wh-a',
            'status' => 'planned',
            'progress' => 0,
            'company_id' => 'co-test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('deliveries')->insert([
            'id' => 'dlv-geo',
            'payload' => json_encode([
                'id' => 'dlv-geo',
                'client' => 'Market Beauséjour',
                'address' => 'Beauséjour',
                'city' => 'Casablanca',
                'scheduledAt' => now()->toIso8601String(),
                'status' => 'prepared',
                'driverId' => 'drv-1',
                'vehicleId' => 'veh-1',
                'routeId' => 'rt-geo',
                'sequence' => 1,
                'warehouseId' => 'wh-a',
                'location' => ['lat' => 33.5712, 'lng' => -7.6498, 'label' => 'Market Beauséjour'],
                'progress' => 0,
                'lines' => [],
                'shippingNoteId' => 'bl-geo',
                'companyId' => 'co-test',
            ]),
            'client' => 'Market Beauséjour',
            'city' => 'Casablanca',
            'status' => 'prepared',
            'route_id' => 'rt-geo',
            'warehouse_id' => 'wh-a',
            'company_id' => 'co-test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('shipping_notes')->insert([
            'id' => 'bl-geo',
            'payload' => json_encode([
                'id' => 'bl-geo',
                'code' => 'BL-GEO-1',
                'warehouseId' => 'wh-a',
                'client' => 'Market Beauséjour',
                'address' => 'Beauséjour',
                'city' => 'Casablanca',
                'status' => 'assigned',
                'deliveryId' => 'dlv-geo',
                'lines' => [],
                'companyId' => 'co-test',
            ]),
            'code' => 'BL-GEO-1',
            'warehouse_id' => 'wh-a',
            'client' => 'Market Beauséjour',
            'address' => 'Beauséjour',
            'city' => 'Casablanca',
            'status' => 'assigned',
            'delivery_id' => 'dlv-geo',
            'company_id' => 'co-test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_customer_geo_seeder_writes_lat_lng_and_links_notes(): void
    {
        $this->seed(CustomerGeoSeeder::class);

        $customer = Customer::query()->where('name', 'Market Beauséjour')->first();
        $this->assertNotNull($customer);
        $doc = $customer->document();
        $this->assertNotEmpty($doc['addresses']);
        $this->assertEqualsWithDelta(33.5712, $doc['addresses'][0]['lat'], 0.0001);
        $this->assertEqualsWithDelta(-7.6498, $doc['addresses'][0]['lng'], 0.0001);

        $note = ShippingNote::query()->find('bl-geo')?->document();
        $this->assertSame($customer->id, $note['customerId'] ?? null);
        $this->assertSame($doc['addresses'][0]['id'], $note['addressId'] ?? null);
    }

    public function test_new_delivery_prefers_customer_address_coordinates(): void
    {
        $this->seed(CustomerGeoSeeder::class);
        $this->actingAs(User::query()->find('usr-admin'), 'api');

        // Note prête liée au même client géolocalisé.
        DB::table('shipping_notes')->insert([
            'id' => 'bl-geo-2',
            'payload' => json_encode([
                'id' => 'bl-geo-2',
                'code' => 'BL-GEO-2',
                'warehouseId' => 'wh-a',
                'customerId' => Customer::query()->where('name', 'Market Beauséjour')->value('id'),
                'addressId' => Customer::query()->where('name', 'Market Beauséjour')->first()->document()['addresses'][0]['id'],
                'client' => 'Market Beauséjour',
                'address' => 'Beauséjour',
                'city' => 'Casablanca',
                'status' => 'ready',
                'lines' => [],
                'companyId' => 'co-test',
            ]),
            'code' => 'BL-GEO-2',
            'warehouse_id' => 'wh-a',
            'customer_id' => Customer::query()->where('name', 'Market Beauséjour')->value('id'),
            'address_id' => Customer::query()->where('name', 'Market Beauséjour')->first()->document()['addresses'][0]['id'],
            'client' => 'Market Beauséjour',
            'address' => 'Beauséjour',
            'city' => 'Casablanca',
            'status' => 'ready',
            'company_id' => 'co-test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $delivery = app(DeliveryService::class)->create([
            'shippingNoteId' => 'bl-geo-2',
            'client' => 'Market Beauséjour',
            'address' => 'Beauséjour',
            'city' => 'Casablanca',
            'scheduledAt' => now()->addHour()->toIso8601String(),
            'routeId' => 'rt-geo',
            'sequence' => 2,
            'lines' => [],
        ]);

        $this->assertEqualsWithDelta(33.5712, $delivery['location']['lat'], 0.0001);
        $this->assertEqualsWithDelta(-7.6498, $delivery['location']['lng'], 0.0001);
    }
}
