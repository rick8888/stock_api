<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchaseFromOfferTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);

        $this->buyer = User::query()->create([
            'id' => 'usr-buyer',
            'first_name' => 'Sara',
            'last_name' => 'Achats',
            'email' => 'buyer-offer@test.local',
            'password' => 'demo',
            'role' => 'PROCUREMENT',
            'title' => 'Acheteur',
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
                'companyId' => 'co-test',
                'active' => true,
            ]),
            'company_id' => 'co-test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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

        DB::table('products')->insert([
            'id' => 'prd-a',
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
                'quantity' => 2,
                'reserved' => 0,
                'enabled' => true,
            ]),
            'product_id' => 'prd-a',
            'warehouse_id' => 'wh-a',
            'quantity' => 2,
            'reserved' => 0,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('procurement_requests')->insert([
            'id' => 'pr-offer',
            'payload' => json_encode([
                'id' => 'pr-offer',
                'code' => 'DA-2026-00999',
                'status' => 'approved',
                'requesterId' => 'usr-buyer',
                'warehouseId' => 'wh-a',
                'items' => [['productId' => 'prd-a', 'quantity' => 20]],
                'note' => 'À commander',
                'priority' => 'high',
                'assigneeId' => 'usr-buyer',
                'assignedRole' => 'PROCUREMENT',
                'takenAt' => now()->toIso8601String(),
                'createdAt' => now()->toIso8601String(),
                'companyId' => 'co-test',
            ]),
            'code' => 'DA-2026-00999',
            'status' => 'approved',
            'requester_id' => 'usr-buyer',
            'warehouse_id' => 'wh-a',
            'company_id' => 'co-test',
            'priority' => 'high',
            'requested_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('procurement_request_items')->insert([
            'id' => 'pri-offer',
            'procurement_request_id' => 'pr-offer',
            'product_id' => 'prd-a',
            'quantity' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_create_from_offer_creates_order_and_marks_request(): void
    {
        $this->actingAs($this->buyer, 'api');
        $response = $this->postJson('/api/purchases/from-offer', [
            'requestIds' => ['pr-offer'],
            'supplierId' => 'sup-atlas',
        ]);
        $response->assertOk();
        $this->assertSame('sup-atlas', $response->json('data.supplierId') ?? $response->json('supplierId'));
        $this->assertDatabaseHas('procurement_requests', [
            'id' => 'pr-offer',
            'status' => 'ordered',
        ]);
    }
}
