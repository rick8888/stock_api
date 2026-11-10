<?php

namespace Tests\Feature;

use App\Services\AlertEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AlertEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);
        DB::table('warehouses')->insert([
            'id' => 'wh-a',
            'payload' => json_encode([
                'id' => 'wh-a',
                'name' => 'Casa',
                'companyId' => 'co-test',
                'active' => true,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('products')->insert([
            'id' => 'prd-1',
            'payload' => json_encode([
                'id' => 'prd-1',
                'name' => 'Huile',
                'sku' => 'HUI-1',
                'minStock' => 50,
                'archived' => false,
                'companyId' => 'co-test',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('stock_items')->insert([
            'id' => 'stk-1',
            'payload' => json_encode([
                'id' => 'stk-1',
                'productId' => 'prd-1',
                'warehouseId' => 'wh-a',
                'quantity' => 10,
                'reserved' => 0,
                'enabled' => true,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('purchase_orders')->insert([
            'id' => 'po-1',
            'payload' => json_encode([
                'id' => 'po-1',
                'code' => 'OA-2026-00001',
                'status' => 'confirmed',
                'warehouseId' => 'wh-a',
                'expectedDate' => now()->subDays(3)->toDateString(),
                'companyId' => 'co-test',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_engine_creates_low_stock_and_delayed_order_alerts(): void
    {
        $result = app(AlertEngine::class)->run();
        $this->assertGreaterThanOrEqual(2, $result['created']);

        $second = app(AlertEngine::class)->run();
        $this->assertSame(0, $second['created']);
        $this->assertGreaterThanOrEqual(2, $second['skipped']);
    }
}
