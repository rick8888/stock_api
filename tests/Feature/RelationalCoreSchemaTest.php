<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Repositories\Eloquent\StockItemRepository;
use App\Repositories\Eloquent\WarehouseRepository;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelationalCoreSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);
    }

    public function test_warehouse_and_stock_persist_indexed_columns(): void
    {
        $warehouse = (new Warehouse)->fillDocument([
            'id' => 'wh-rel',
            'name' => 'Casa Rel',
            'city' => 'Casablanca',
            'regionId' => 'reg-1',
            'address' => 'Zone',
            'managerUserId' => 'usr-1',
            'capacity' => 1000,
            'active' => true,
            'companyId' => 'co-rel',
        ]);
        $warehouse->save();

        $stock = (new StockItem)->fillDocument([
            'id' => 'stk-rel',
            'productId' => 'prd-rel',
            'warehouseId' => 'wh-rel',
            'quantity' => 42,
            'reserved' => 2,
            'enabled' => true,
        ]);
        $stock->save();

        $this->assertDatabaseHas('warehouses', [
            'id' => 'wh-rel',
            'name' => 'Casa Rel',
            'company_id' => 'co-rel',
            'active' => 1,
        ]);
        $this->assertDatabaseHas('stock_items', [
            'id' => 'stk-rel',
            'product_id' => 'prd-rel',
            'warehouse_id' => 'wh-rel',
            'quantity' => 42,
            'reserved' => 2,
        ]);

        $repo = app(StockItemRepository::class);
        $line = $repo->findByProductWarehouse('prd-rel', 'wh-rel');
        $this->assertNotNull($line);
        $this->assertSame(42, (int) $line['quantity']);
        $this->assertSame(2, (int) $line['reserved']);
    }

    public function test_tenant_company_warehouse_ids_uses_sql_column(): void
    {
        $actor = User::query()->create([
            'id' => 'usr-rel',
            'first_name' => 'Rel',
            'last_name' => 'Ops',
            'email' => 'rel@test.local',
            'password' => 'demo',
            'role' => 'ADMIN',
            'title' => 'Admin',
            'avatar_hue' => 10,
            'warehouse_ids' => [],
            'product_ids' => [],
            'region_ids' => [],
            'company_id' => 'co-rel',
        ]);
        $this->actingAs($actor, 'api');

        (new Warehouse)->fillDocument([
            'id' => 'wh-a',
            'name' => 'A',
            'city' => 'Casa',
            'companyId' => 'co-rel',
            'active' => true,
            'regionId' => 'r',
            'address' => 'x',
            'managerUserId' => 'usr-rel',
            'capacity' => 1,
        ])->save();
        (new Warehouse)->fillDocument([
            'id' => 'wh-b',
            'name' => 'B',
            'city' => 'Rabat',
            'companyId' => 'co-other',
            'active' => true,
            'regionId' => 'r',
            'address' => 'x',
            'managerUserId' => 'usr-rel',
            'capacity' => 1,
        ])->save();

        $ids = app(Tenant::class)->companyWarehouseIds();
        $this->assertSame(['wh-a'], $ids);
        $this->assertSame(['wh-a'], app(WarehouseRepository::class)->idsForCompany('co-rel'));
    }

    public function test_legacy_payload_only_stock_row_still_readable(): void
    {
        \Illuminate\Support\Facades\DB::table('stock_items')->insert([
            'id' => 'stk-legacy',
            'payload' => json_encode([
                'id' => 'stk-legacy',
                'productId' => 'prd-legacy',
                'warehouseId' => 'wh-legacy',
                'quantity' => 7,
                'reserved' => 0,
                'enabled' => true,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $repo = app(StockItemRepository::class);
        $line = $repo->findByProductWarehouse('prd-legacy', 'wh-legacy');
        $this->assertNotNull($line);
        $this->assertSame(7, (int) $line['quantity']);
    }

    public function test_product_dual_write_keeps_extra_payload_fields(): void
    {
        (new Product)->fillDocument([
            'id' => 'prd-extra',
            'sku' => 'SKU-1',
            'name' => 'Produit',
            'categoryId' => 'cat-1',
            'unit' => 'u',
            'minStock' => 1,
            'price' => 10,
            'supplierId' => 'sup-1',
            'archived' => false,
            'dailyDemand' => 1,
            'description' => 'Extra hors colonnes',
            'images' => ['a.png'],
            'companyId' => 'co-rel',
        ])->save();

        $doc = Product::query()->find('prd-extra')?->document();
        $this->assertSame('SKU-1', $doc['sku'] ?? null);
        $this->assertSame('Extra hors colonnes', $doc['description'] ?? null);
        $this->assertSame(['a.png'], $doc['images'] ?? null);
        $this->assertDatabaseHas('products', [
            'id' => 'prd-extra',
            'sku' => 'SKU-1',
            'company_id' => 'co-rel',
        ]);
    }
}
