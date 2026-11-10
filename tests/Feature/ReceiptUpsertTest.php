<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ReceiptService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReceiptUpsertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);
        $actor = User::query()->create([
            'id' => 'usr-rcp',
            'first_name' => 'Récep',
            'last_name' => 'Tion',
            'email' => 'rcp@test.local',
            'password' => 'demo',
            'role' => 'WAREHOUSE_MANAGER',
            'title' => 'Magasinier',
            'avatar_hue' => 8,
            'warehouse_ids' => ['wh-a'],
            'product_ids' => [],
            'region_ids' => [],
            'company_id' => 'co-test',
        ]);
        $this->actingAs($actor, 'api');
        DB::table('warehouses')->insert([
            'id' => 'wh-a',
            'payload' => json_encode([
                'id' => 'wh-a',
                'name' => 'Entrepôt A',
                'city' => 'Casa',
                'companyId' => 'co-test',
                'active' => true,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('receipts')->insert([
            'id' => 'rcp-1',
            'payload' => json_encode([
                'id' => 'rcp-1',
                'code' => 'BR-2026-00001',
                'warehouseId' => 'wh-a',
                'purchaseOrderId' => null,
                'status' => 'pending',
                'expected' => 15,
                'received' => 0,
                'items' => [
                    ['productId' => 'prd-new', 'expected' => 15, 'received' => 0],
                ],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_validate_creates_missing_stock_line(): void
    {
        $receipts = app(ReceiptService::class);
        $stock = app(StockService::class);

        $this->assertNull($stock->tryLine('prd-new', 'wh-a'));

        $receipts->validate('rcp-1', 15, 'usr-rcp', [
            ['productId' => 'prd-new', 'received' => 15],
        ]);

        $line = $stock->line('prd-new', 'wh-a');
        $this->assertSame(15, (int) $line['quantity']);
    }
}
