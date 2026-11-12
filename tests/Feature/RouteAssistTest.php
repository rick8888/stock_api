<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RouteAssistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RouteAssistTest extends TestCase
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
            'email' => 'dispatch-assist@test.local',
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
                'lat' => 33.5731,
                'lng' => -7.5898,
                'companyId' => 'co-test',
                'active' => true,
            ]),
            'company_id' => 'co-test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('shipping_notes')->insert([
            [
                'id' => 'bl-far',
                'payload' => json_encode([
                    'id' => 'bl-far',
                    'code' => 'BL-FAR',
                    'warehouseId' => 'wh-a',
                    'client' => 'Client Rabat',
                    'address' => 'Av. Rabat',
                    'city' => 'Rabat',
                    'status' => 'ready',
                    'lines' => [],
                    'companyId' => 'co-test',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 'bl-near',
                'payload' => json_encode([
                    'id' => 'bl-near',
                    'code' => 'BL-NEAR',
                    'warehouseId' => 'wh-a',
                    'client' => 'Client Maarif',
                    'address' => 'Bd Maarif',
                    'city' => 'Casablanca',
                    'status' => 'ready',
                    'lines' => [],
                    'companyId' => 'co-test',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 'bl-risk',
                'payload' => json_encode([
                    'id' => 'bl-risk',
                    'code' => 'BL-RISK',
                    'warehouseId' => 'wh-a',
                    'client' => 'Client Risk',
                    'address' => 'Bd Risk',
                    'city' => 'Casablanca',
                    'status' => 'ready',
                    'lines' => [],
                    'companyId' => 'co-test',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('vehicles')->insert([
            'id' => 'veh-a',
            'name' => 'Camion A',
            'plate' => 'A 1',
            'status' => 'available',
            'warehouse_id' => 'wh-a',
            'company_id' => 'co-test',
            'payload' => json_encode([
                'id' => 'veh-a',
                'name' => 'Camion A',
                'plate' => 'A 1',
                'status' => 'available',
                'warehouseId' => 'wh-a',
                'companyId' => 'co-test',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('drivers')->insert([
            'id' => 'drv-a',
            'user_id' => 'usr-dispatch',
            'vehicle_id' => 'veh-a',
            'status' => 'available',
            'warehouse_id' => 'wh-a',
            'company_id' => 'co-test',
            'payload' => json_encode([
                'id' => 'drv-a',
                'userId' => 'usr-dispatch',
                'phone' => '0600000000',
                'status' => 'available',
                'vehicleId' => 'veh-a',
                'warehouseId' => 'wh-a',
                'companyId' => 'co-test',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_suggest_prioritizes_at_risk_and_returns_reasons(): void
    {
        $result = app(RouteAssistService::class)->suggest([
            'warehouseId' => 'wh-a',
            'date' => now()->toDateString(),
            'stops' => [
                [
                    'key' => 's-far',
                    'shippingNoteId' => 'bl-far',
                    'city' => 'Rabat',
                    'status' => 'prepared',
                    'scheduledAt' => now()->setTime(14, 0)->toIso8601String(),
                    'lat' => 34.0209,
                    'lng' => -6.8416,
                ],
                [
                    'key' => 's-near',
                    'shippingNoteId' => 'bl-near',
                    'city' => 'Casablanca',
                    'status' => 'prepared',
                    'scheduledAt' => now()->setTime(11, 0)->toIso8601String(),
                    'lat' => 33.58,
                    'lng' => -7.63,
                ],
                [
                    'key' => 's-risk',
                    'shippingNoteId' => 'bl-risk',
                    'city' => 'Casablanca',
                    'status' => 'at_risk',
                    'scheduledAt' => now()->setTime(16, 0)->toIso8601String(),
                    'lat' => 33.59,
                    'lng' => -7.62,
                ],
            ],
        ]);

        $this->assertCount(3, $result['stops']);
        $this->assertSame('s-risk', $result['stops'][0]['key']);
        $this->assertNotEmpty($result['stops'][0]['reasons']);
        $this->assertNotEmpty($result['reasons']);
        $this->assertGreaterThan(0, $result['distanceKm']);
        $this->assertNotEmpty($result['drivers']);
        $this->assertTrue($result['drivers'][0]['recommended']);
        $this->assertNotEmpty($result['vehicles']);
        $this->assertTrue($result['vehicles'][0]['recommended']);
    }

    public function test_assist_endpoint_requires_auth_and_returns_payload(): void
    {
        $this->actingAs($this->dispatcher, 'api');
        $response = $this->postJson('/api/routes/assist', [
            'warehouseId' => 'wh-a',
            'stops' => [
                [
                    'key' => 's1',
                    'shippingNoteId' => 'bl-near',
                    'city' => 'Casablanca',
                    'status' => 'prepared',
                    'lat' => 33.58,
                    'lng' => -7.63,
                ],
            ],
        ]);
        $response->assertOk();
        $response->assertJsonPath('warehouseId', 'wh-a');
        $this->assertSame('s1', $response->json('stops.0.key'));
        $this->assertNotEmpty($response->json('reasons'));
        $this->assertNotEmpty($response->json('drivers'));
        $this->assertTrue($response->json('drivers.0.recommended'));
        $this->assertNotEmpty($response->json('vehicles'));
    }
}
