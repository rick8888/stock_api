<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LogisticsBoardTest extends TestCase
{
    use RefreshDatabase;

    private User $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);

        $this->dispatcher = User::query()->create([
            'id' => 'usr-log-board',
            'first_name' => 'Nadia',
            'last_name' => 'Dispatch',
            'email' => 'log-board@test.local',
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
                'address' => 'Zone indus',
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
            'id' => 'prd-a',
            'payload' => json_encode([
                'id' => 'prd-a',
                'name' => 'Huile',
                'sku' => 'HUI',
                'unit' => 'bidon',
                'archived' => false,
                'companyId' => 'co-test',
            ]),
            'name' => 'Huile',
            'sku' => 'HUI',
            'archived' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        for ($i = 1; $i <= 10; $i++) {
            $id = 'dlv-'.$i;
            $status = $i <= 2 ? 'at_risk' : ($i <= 5 ? 'in_transit' : ($i <= 7 ? 'prepared' : 'delivered'));
            DB::table('deliveries')->insert([
                'id' => $id,
                'payload' => json_encode([
                    'id' => $id,
                    'code' => 'LV-2026-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                    'client' => 'Client '.$i,
                    'city' => 'Casa',
                    'address' => 'Rue '.$i,
                    'warehouseId' => 'wh-a',
                    'driverId' => 'drv-1',
                    'vehicleId' => 'veh-1',
                    'status' => $status,
                    'scheduledAt' => now()->addHours($i)->toIso8601String(),
                    'sequence' => $i,
                    'progress' => $status === 'delivered' ? 100 : 40,
                    'location' => ['lat' => 33.6, 'lng' => -7.5, 'label' => 'Client '.$i],
                    'lines' => [['productId' => 'prd-a', 'productName' => 'Huile', 'sku' => 'HUI', 'quantity' => 2, 'unit' => 'bidon']],
                    'companyId' => 'co-test',
                ]),
                'warehouse_id' => 'wh-a',
                'company_id' => 'co-test',
                'status' => $status,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('shipping_notes')->insert([
            'id' => 'bl-1',
            'payload' => json_encode([
                'id' => 'bl-1',
                'code' => 'BL-2026-00001',
                'client' => 'Client 1',
                'city' => 'Casa',
                'address' => 'Rue 1',
                'warehouseId' => 'wh-a',
                'status' => 'ready',
                'lines' => [['productId' => 'prd-a', 'productName' => 'Huile', 'sku' => 'HUI', 'quantity' => 2, 'unit' => 'bidon']],
                'createdAt' => now()->toIso8601String(),
                'companyId' => 'co-test',
            ]),
            'warehouse_id' => 'wh-a',
            'company_id' => 'co-test',
            'status' => 'ready',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_deliveries_board_paginates_and_counts(): void
    {
        $this->actingAs($this->dispatcher, 'api');
        $response = $this->getJson('/api/logistics/board?section=deliveries&filter=all&page=1&pageSize=4');
        $response->assertOk();
        $response->assertJsonPath('section', 'deliveries');
        $response->assertJsonPath('counts.all', 10);
        $response->assertJsonPath('counts.at_risk', 2);
        $response->assertJsonPath('pageSize', 4);
        $response->assertJsonPath('pages', 3);
        $this->assertCount(4, $response->json('items'));
        $this->assertArrayHasKey('driverName', $response->json('items.0'));
        $this->assertArrayHasKey('metrics', $response->json());
        $this->assertArrayHasKey('formOptions', $response->json());
        $this->assertNotEmpty($response->json('formOptions.warehouses'));
        $this->assertNotEmpty($response->json('formOptions.products'));
    }

    public function test_notes_board_filter_ready(): void
    {
        $this->actingAs($this->dispatcher, 'api');
        $response = $this->getJson('/api/logistics/board?section=notes&filter=ready&page=1&pageSize=8');
        $response->assertOk();
        $response->assertJsonPath('section', 'notes');
        $response->assertJsonPath('total', 1);
        $this->assertSame('bl-1', $response->json('items.0.note.id'));
        $this->assertSame('Casa', $response->json('items.0.warehouseName'));
    }
}
