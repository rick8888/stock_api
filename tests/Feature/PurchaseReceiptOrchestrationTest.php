<?php

namespace Tests\Feature;

use App\Models\User;
use App\Repositories\Eloquent\ReceiptRepository;
use App\Repositories\Eloquent\TaskItemRepository;
use App\Services\PurchaseService;
use App\Services\ReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchaseReceiptOrchestrationTest extends TestCase
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
            'last_name' => 'Achat',
            'email' => 'buyer@test.local',
            'password' => 'demo',
            'role' => 'PROCUREMENT',
            'title' => 'Achat',
            'avatar_hue' => 20,
            'warehouse_ids' => ['wh-a'],
            'product_ids' => [],
            'region_ids' => [],
            'company_id' => 'co-test',
        ]);

        User::query()->create([
            'id' => 'usr-wh',
            'first_name' => 'Karim',
            'last_name' => 'Magasin',
            'email' => 'wh@test.local',
            'password' => 'demo',
            'role' => 'WAREHOUSE_MANAGER',
            'title' => 'Magasinier',
            'avatar_hue' => 60,
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
                'managerUserId' => 'usr-wh',
                'companyId' => 'co-test',
                'active' => true,
            ]),
            'name' => 'Casa',
            'city' => 'Casablanca',
            'manager_user_id' => 'usr-wh',
            'company_id' => 'co-test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('suppliers')->insert([
            'id' => 'sup-1',
            'payload' => json_encode(['id' => 'sup-1', 'name' => 'Fournisseur Test', 'companyId' => 'co-test']),
            'name' => 'Fournisseur Test',
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
    }

    public function test_creating_a_purchase_order_auto_creates_receipt_and_task(): void
    {
        $this->actingAs($this->buyer, 'api');

        $order = app(PurchaseService::class)->create([
            'supplierId' => 'sup-1',
            'warehouseId' => 'wh-a',
            'status' => 'sent',
            'expectedDate' => now()->addDays(5)->toDateString(),
            'items' => [
                ['productId' => 'prd-huile', 'quantity' => 20, 'unitPrice' => 10],
            ],
            'procurementRequestIds' => [],
        ]);

        $receipt = app(ReceiptRepository::class)->all()
            ->first(fn (array $item) => ($item['purchaseOrderId'] ?? null) === $order['id']);
        $this->assertNotNull($receipt);
        $this->assertSame('pending', $receipt['status']);
        $this->assertSame(20, (int) ($receipt['expected'] ?? 0));

        $task = app(TaskItemRepository::class)->all()
            ->first(fn (array $item) => ($item['relatedId'] ?? null) === $receipt['id']);
        $this->assertNotNull($task);
        $this->assertSame('usr-wh', $task['assigneeId']);
    }

    public function test_create_from_order_is_idempotent(): void
    {
        $this->actingAs($this->buyer, 'api');
        $order = app(PurchaseService::class)->create([
            'supplierId' => 'sup-1',
            'warehouseId' => 'wh-a',
            'status' => 'sent',
            'expectedDate' => now()->addDays(3)->toDateString(),
            'items' => [
                ['productId' => 'prd-huile', 'quantity' => 5, 'unitPrice' => 10],
            ],
        ]);

        $first = app(ReceiptService::class)->createFromOrder($order['id']);
        $second = app(ReceiptService::class)->createFromOrder($order['id']);
        $this->assertSame($first['id'], $second['id']);
        $this->assertCount(1, app(ReceiptRepository::class)->all());
    }

    public function test_carriers_endpoint_returns_catalog(): void
    {
        $dispatcher = User::query()->create([
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
        $this->actingAs($dispatcher, 'api');

        $this->getJson('/api/carriers')
            ->assertOk()
            ->assertJsonFragment(['id' => 'car-fleet'])
            ->assertJsonFragment(['id' => 'car-ctm']);
    }

    public function test_from_order_endpoint(): void
    {
        $this->actingAs($this->buyer, 'api');
        // OA sans passer par create() auto — simule un OA orphelin.
        DB::table('purchase_orders')->insert([
            'id' => 'po-orphan',
            'payload' => json_encode([
                'id' => 'po-orphan',
                'code' => 'OA-ORPHAN',
                'supplierId' => 'sup-1',
                'warehouseId' => 'wh-a',
                'status' => 'sent',
                'expectedDate' => now()->addDays(2)->toDateString(),
                'items' => [
                    ['productId' => 'prd-huile', 'quantity' => 8, 'unitPrice' => 10],
                ],
                'companyId' => 'co-test',
            ]),
            'code' => 'OA-ORPHAN',
            'supplier_id' => 'sup-1',
            'warehouse_id' => 'wh-a',
            'status' => 'sent',
            'company_id' => 'co-test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/receipts/from-order/po-orphan')
            ->assertOk()
            ->assertJsonPath('purchaseOrderId', 'po-orphan');
    }
}
