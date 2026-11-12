<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\StockLot;
use App\Services\AlertEngine;
use App\Support\AlertVisibility;
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
                'unit' => 'L',
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
        (new StockLot)->fillDocument([
            'id' => 'lot-1',
            'productId' => 'prd-1',
            'warehouseId' => 'wh-a',
            'lotCode' => 'L-EXPIRE',
            'quantity' => 8,
            'expiryDate' => now()->addDays(5)->toDateString(),
            'companyId' => 'co-test',
        ])->save();
    }

    public function test_engine_creates_low_stock_delayed_order_and_dlc_alerts(): void
    {
        $result = app(AlertEngine::class)->run();
        $this->assertGreaterThanOrEqual(3, $result['created']);

        $alerts = Alert::query()->get()->map(fn (Alert $row) => $row->document());
        $byCode = $alerts->keyBy(fn (array $row) => explode(':', (string) ($row['code'] ?? ''))[0] ?? '');

        $this->assertSame('replenish', $byCode['low_stock']['action'] ?? null);
        $this->assertSame('prd-1', $byCode['low_stock']['relatedId'] ?? null);
        $this->assertSame('receive', $byCode['po_delay']['action'] ?? null);
        $this->assertSame('po-1', $byCode['po_delay']['relatedId'] ?? null);
        $this->assertSame('stock', $byCode['lot_expiry']['action'] ?? null);

        $this->assertSame(
            '/analytics/forecasts?product=prd-1&warehouse=wh-a&action=da',
            AlertVisibility::href($byCode['low_stock'], 'PROCUREMENT'),
        );
        $this->assertSame('Créer la DA', AlertVisibility::actionLabel($byCode['low_stock']));
        $this->assertSame('Ouvrir le BR', AlertVisibility::actionLabel($byCode['po_delay']));
        $this->assertSame(
            '/procurement?tab=orders&order=po-1&action=receive',
            AlertVisibility::href($byCode['po_delay'], 'PROCUREMENT'),
        );

        $second = app(AlertEngine::class)->run();
        $this->assertSame(0, $second['created']);
        $this->assertGreaterThanOrEqual(3, $second['skipped']);
    }
}
