<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockReservationTest extends TestCase
{
    use RefreshDatabase;

    private StockService $stock;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);
        $this->actor = User::query()->create([
            'id' => 'usr-test',
            'first_name' => 'Test',
            'last_name' => 'Ops',
            'email' => 'ops@test.local',
            'password' => 'demo',
            'role' => 'WAREHOUSE_MANAGER',
            'title' => 'Magasinier',
            'avatar_hue' => 12,
            'warehouse_ids' => ['wh-a', 'wh-b'],
            'product_ids' => [],
            'region_ids' => [],
            'company_id' => 'co-test',
        ]);
        $this->actingAs($this->actor, 'api');
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
        DB::table('warehouses')->insert([
            'id' => 'wh-b',
            'payload' => json_encode([
                'id' => 'wh-b',
                'name' => 'Entrepôt B',
                'city' => 'Rabat',
                'companyId' => 'co-test',
                'active' => true,
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
                'quantity' => 100,
                'reserved' => 0,
                'enabled' => true,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->stock = app(StockService::class);
    }

    public function test_reserve_reduces_available_without_touching_physical(): void
    {
        $this->stock->reserve('prd-1', 'wh-a', 30);
        $line = $this->stock->line('prd-1', 'wh-a');

        $this->assertSame(100, (int) $line['quantity']);
        $this->assertSame(30, (int) $line['reserved']);
        $this->assertSame(70, $this->stock->available($line));
    }

    public function test_cannot_over_reserve(): void
    {
        $this->expectExceptionMessage('Stock disponible insuffisant');
        $this->stock->reserve('prd-1', 'wh-a', 120);
    }

    public function test_consume_reservation_debits_physical_and_reserved(): void
    {
        $this->stock->reserve('prd-1', 'wh-a', 25);
        $this->stock->consumeReservation('prd-1', 'wh-a', 25, $this->actor->id, 'Chargement BL', 'BL-1');
        $line = $this->stock->line('prd-1', 'wh-a');

        $this->assertSame(75, (int) $line['quantity']);
        $this->assertSame(0, (int) $line['reserved']);
    }

    public function test_issue_respects_available_not_just_physical(): void
    {
        $this->stock->reserve('prd-1', 'wh-a', 80);
        $this->expectExceptionMessage('Stock insuffisant');
        $this->stock->issue('prd-1', 'wh-a', 30, $this->actor->id, 'Sortie libre', 'BL-TEST');
    }

    public function test_transfer_creates_destination_line_when_missing(): void
    {
        $this->stock->transfer('prd-1', 'wh-a', 'wh-b', 10, $this->actor->id, 'Transfert test');
        $dest = $this->stock->line('prd-1', 'wh-b');
        $origin = $this->stock->line('prd-1', 'wh-a');

        $this->assertSame(10, (int) $dest['quantity']);
        $this->assertSame(90, (int) $origin['quantity']);
    }
}
