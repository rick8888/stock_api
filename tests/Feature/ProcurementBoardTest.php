<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProcurementBoardTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);

        $this->manager = User::query()->create([
            'id' => 'usr-proc',
            'first_name' => 'Amine',
            'last_name' => 'Achats',
            'email' => 'proc-board@test.local',
            'password' => 'demo',
            'role' => 'MANAGER',
            'title' => 'Ops',
            'avatar_hue' => 30,
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
                'companyId' => 'co-test',
                'active' => true,
            ]),
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
                'price' => 100,
                'archived' => false,
                'companyId' => 'co-test',
            ]),
            'name' => 'Huile',
            'sku' => 'HUI',
            'archived' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('stock_items')->insert([
            'id' => 'stk-a',
            'payload' => json_encode([
                'id' => 'stk-a',
                'productId' => 'prd-a',
                'warehouseId' => 'wh-a',
                'quantity' => 5,
                'reserved' => 0,
                'enabled' => true,
            ]),
            'product_id' => 'prd-a',
            'warehouse_id' => 'wh-a',
            'quantity' => 5,
            'reserved' => 0,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        for ($i = 1; $i <= 10; $i++) {
            $id = 'pr-'.$i;
            $status = $i <= 3 ? 'submitted' : ($i <= 6 ? 'approved' : 'ordered');
            $createdAt = now()->subMinutes(10 - $i);
            DB::table('procurement_requests')->insert([
                'id' => $id,
                'payload' => json_encode([
                    'id' => $id,
                    'code' => 'DA-2026-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                    'status' => $status,
                    'requesterId' => 'usr-proc',
                    'warehouseId' => 'wh-a',
                    'items' => [['productId' => 'prd-a', 'quantity' => 20]],
                    'note' => 'Demande '.$i,
                    'priority' => 'medium',
                    'createdAt' => $createdAt->toIso8601String(),
                    'companyId' => 'co-test',
                ]),
                'code' => 'DA-2026-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'status' => $status,
                'requester_id' => 'usr-proc',
                'warehouse_id' => 'wh-a',
                'company_id' => 'co-test',
                'priority' => 'medium',
                'requested_at' => $createdAt,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
            DB::table('procurement_request_items')->insert([
                'id' => 'pri-'.$i,
                'procurement_request_id' => $id,
                'product_id' => 'prd-a',
                'quantity' => 20,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }
    }

    public function test_requests_board_paginates_and_counts(): void
    {
        $this->actingAs($this->manager, 'api');
        $response = $this->getJson('/api/procurement/board?section=requests&filter=all&page=1&pageSize=4');
        $response->assertOk();
        $response->assertJsonPath('section', 'requests');
        $response->assertJsonPath('counts.all', 10);
        $response->assertJsonPath('counts.submitted', 3);
        $response->assertJsonPath('pageSize', 4);
        $response->assertJsonPath('pages', 3);
        $this->assertCount(4, $response->json('items'));
        $first = $response->json('items.0');
        $this->assertSame('purchase', $first['decision']);
        $this->assertArrayHasKey('lines', $first);
    }

    public function test_filter_submitted_only(): void
    {
        $this->actingAs($this->manager, 'api');
        $response = $this->getJson('/api/procurement/board?section=requests&filter=submitted&page=1&pageSize=8');
        $response->assertOk();
        $response->assertJsonPath('total', 3);
        $this->assertTrue(collect($response->json('items'))->every(
            fn ($item) => ($item['request']['status'] ?? '') === 'submitted'
        ));
    }

    public function test_board_includes_form_options(): void
    {
        $this->actingAs($this->manager, 'api');
        $response = $this->getJson('/api/procurement/board?section=requests&page=1&pageSize=4');
        $response->assertOk();
        $this->assertNotEmpty($response->json('formOptions.products'));
        $this->assertNotEmpty($response->json('formOptions.warehouses'));
        $this->assertSame('prd-a', $response->json('formOptions.products.0.id'));
        $this->assertSame('wh-a', $response->json('formOptions.warehouses.0.id'));
    }

    public function test_suppliers_board_paginates(): void
    {
        DB::table('suppliers')->insert([
            [
                'id' => 'sup-1',
                'payload' => json_encode([
                    'id' => 'sup-1',
                    'name' => 'Atlas',
                    'status' => 'active',
                    'city' => 'Casa',
                    'contact' => 'A',
                    'email' => 'a@test.local',
                    'phone' => '1',
                    'leadTimeDays' => 3,
                    'reliability' => 90,
                    'companyId' => 'co-test',
                ]),
                'company_id' => 'co-test',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 'sup-2',
                'payload' => json_encode([
                    'id' => 'sup-2',
                    'name' => 'Nord',
                    'status' => 'pending',
                    'city' => 'Tanger',
                    'contact' => 'B',
                    'email' => 'b@test.local',
                    'phone' => '2',
                    'leadTimeDays' => 5,
                    'reliability' => 80,
                    'companyId' => 'co-test',
                ]),
                'company_id' => 'co-test',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->actingAs($this->manager, 'api');
        $response = $this->getJson('/api/procurement/board?section=suppliers&filter=all&page=1&pageSize=8');
        $response->assertOk();
        $response->assertJsonPath('section', 'suppliers');
        $response->assertJsonPath('counts.all', 2);
        $response->assertJsonPath('counts.active', 1);
        $this->assertCount(2, $response->json('items'));
        $this->assertCount(2, $response->json('formOptions.suppliers'));
    }

    public function test_sourcing_returns_offers(): void
    {
        DB::table('suppliers')->insert([
            'id' => 'sup-atlas',
            'payload' => json_encode([
                'id' => 'sup-atlas',
                'name' => 'Atlas',
                'status' => 'active',
                'city' => 'Casa',
                'contact' => 'A',
                'email' => 'a@test.local',
                'phone' => '1',
                'leadTimeDays' => 3,
                'reliability' => 95,
                'companyId' => 'co-test',
            ]),
            'company_id' => 'co-test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('products')->where('id', 'prd-a')->update([
            'payload' => json_encode([
                'id' => 'prd-a',
                'name' => 'Huile',
                'sku' => 'HUI',
                'unit' => 'bidon',
                'price' => 100,
                'archived' => false,
                'supplierId' => 'sup-atlas',
                'companyId' => 'co-test',
            ]),
        ]);

        $id = 'pr-4';
        DB::table('procurement_requests')->where('id', $id)->update([
            'status' => 'approved',
            'payload' => json_encode([
                'id' => $id,
                'code' => 'DA-2026-00004',
                'status' => 'approved',
                'requesterId' => 'usr-proc',
                'warehouseId' => 'wh-a',
                'items' => [['productId' => 'prd-a', 'quantity' => 20]],
                'note' => 'Demande 4',
                'priority' => 'medium',
                'assigneeId' => 'usr-proc',
                'assignedRole' => 'PROCUREMENT',
                'takenAt' => now()->toIso8601String(),
                'createdAt' => now()->toIso8601String(),
                'companyId' => 'co-test',
            ]),
        ]);

        DB::table('purchase_orders')->insert([
            [
                'id' => 'po-atlas-ok',
                'supplier_id' => 'sup-atlas',
                'status' => 'received',
                'expected_date' => now()->subDays(1)->toDateString(),
                'company_id' => 'co-test',
                'payload' => json_encode([
                    'id' => 'po-atlas-ok',
                    'supplierId' => 'sup-atlas',
                    'status' => 'received',
                    'expectedDate' => now()->subDays(1)->toDateString(),
                    'companyId' => 'co-test',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 'po-atlas-late',
                'supplier_id' => 'sup-atlas',
                'status' => 'delayed',
                'expected_date' => now()->subDays(4)->toDateString(),
                'company_id' => 'co-test',
                'payload' => json_encode([
                    'id' => 'po-atlas-late',
                    'supplierId' => 'sup-atlas',
                    'status' => 'delayed',
                    'expectedDate' => now()->subDays(4)->toDateString(),
                    'companyId' => 'co-test',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->actingAs($this->manager, 'api');
        $response = $this->getJson('/api/procurement/sourcing?ids='.$id);
        $response->assertOk();
        $response->assertJsonPath('requestIds.0', $id);
        $this->assertNotEmpty($response->json('offers'));
        $this->assertSame('sup-atlas', $response->json('offers.0.supplierId'));
        // 40%*95 + 60%*50 = 68
        $this->assertSame(68, $response->json('offers.0.reliability'));
        $this->assertSame(95, $response->json('offers.0.declaredReliability'));
        $this->assertSame(50, $response->json('offers.0.onTimeRate'));
        $this->assertSame(2, $response->json('offers.0.orderSampleSize'));
        $this->assertNotEmpty($response->json('offers.0.reasons'));
    }
}
