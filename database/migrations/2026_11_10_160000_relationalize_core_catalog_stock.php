<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
            $table->string('company_id')->nullable()->index()->after('name');
        });

        Schema::table('warehouses', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
            $table->string('city')->nullable()->after('name');
            $table->string('region_id')->nullable()->index()->after('city');
            $table->string('address')->nullable()->after('region_id');
            $table->string('manager_user_id')->nullable()->after('address');
            $table->unsignedInteger('capacity')->nullable()->after('manager_user_id');
            $table->boolean('active')->nullable()->index()->after('capacity');
            $table->string('company_id')->nullable()->index()->after('active');
            $table->decimal('lat', 10, 7)->nullable()->after('company_id');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('sku')->nullable()->index()->after('id');
            $table->string('name')->nullable()->after('sku');
            $table->string('category_id')->nullable()->index()->after('name');
            $table->string('unit')->nullable()->after('category_id');
            $table->integer('min_stock')->nullable()->after('unit');
            $table->integer('max_stock')->nullable()->after('min_stock');
            $table->decimal('price', 12, 2)->nullable()->after('max_stock');
            $table->string('supplier_id')->nullable()->index()->after('price');
            $table->boolean('archived')->nullable()->index()->after('supplier_id');
            $table->decimal('daily_demand', 12, 2)->nullable()->after('archived');
            $table->string('barcode')->nullable()->index()->after('daily_demand');
            $table->boolean('track_lots')->nullable()->after('barcode');
            $table->string('company_id')->nullable()->index()->after('track_lots');
        });

        Schema::table('stock_items', function (Blueprint $table) {
            $table->string('product_id')->nullable()->index()->after('id');
            $table->string('warehouse_id')->nullable()->index()->after('product_id');
            $table->integer('quantity')->nullable()->after('warehouse_id');
            $table->integer('reserved')->nullable()->after('quantity');
            $table->boolean('enabled')->nullable()->index()->after('reserved');
            $table->unique(['product_id', 'warehouse_id'], 'stock_items_product_warehouse_unique');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('product_id')->nullable()->index()->after('id');
            $table->string('type')->nullable()->index()->after('product_id');
            $table->integer('quantity')->nullable()->after('type');
            $table->string('from_warehouse_id')->nullable()->index()->after('quantity');
            $table->string('to_warehouse_id')->nullable()->index()->after('from_warehouse_id');
            $table->string('user_id')->nullable()->index()->after('to_warehouse_id');
            $table->timestamp('moved_at')->nullable()->index()->after('user_id');
            $table->string('note')->nullable()->after('moved_at');
            $table->string('reference')->nullable()->index()->after('note');
        });

        $this->backfill('categories', [
            'name' => 'name',
            'company_id' => 'companyId',
        ]);
        $this->backfill('warehouses', [
            'name' => 'name',
            'city' => 'city',
            'region_id' => 'regionId',
            'address' => 'address',
            'manager_user_id' => 'managerUserId',
            'capacity' => 'capacity',
            'active' => 'active',
            'company_id' => 'companyId',
            'lat' => 'lat',
            'lng' => 'lng',
        ]);
        $this->backfill('products', [
            'sku' => 'sku',
            'name' => 'name',
            'category_id' => 'categoryId',
            'unit' => 'unit',
            'min_stock' => 'minStock',
            'max_stock' => 'maxStock',
            'price' => 'price',
            'supplier_id' => 'supplierId',
            'archived' => 'archived',
            'daily_demand' => 'dailyDemand',
            'barcode' => 'barcode',
            'track_lots' => 'trackLots',
            'company_id' => 'companyId',
        ]);
        $this->backfill('stock_items', [
            'product_id' => 'productId',
            'warehouse_id' => 'warehouseId',
            'quantity' => 'quantity',
            'reserved' => 'reserved',
            'enabled' => 'enabled',
        ]);
        $this->backfillMovements();
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn([
                'product_id', 'type', 'quantity', 'from_warehouse_id', 'to_warehouse_id',
                'user_id', 'moved_at', 'note', 'reference',
            ]);
        });
        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropUnique('stock_items_product_warehouse_unique');
            $table->dropColumn(['product_id', 'warehouse_id', 'quantity', 'reserved', 'enabled']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'sku', 'name', 'category_id', 'unit', 'min_stock', 'max_stock', 'price',
                'supplier_id', 'archived', 'daily_demand', 'barcode', 'track_lots', 'company_id',
            ]);
        });
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn([
                'name', 'city', 'region_id', 'address', 'manager_user_id', 'capacity',
                'active', 'company_id', 'lat', 'lng',
            ]);
        });
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['name', 'company_id']);
        });
    }

    /** @param array<string, string> $map column => payloadKey */
    private function backfill(string $table, array $map): void
    {
        DB::table($table)->orderBy('id')->chunkById(200, function ($rows) use ($table, $map) {
            foreach ($rows as $row) {
                $payload = json_decode($row->payload ?? '[]', true) ?: [];
                $update = [];
                foreach ($map as $column => $key) {
                    if (! array_key_exists($key, $payload)) {
                        continue;
                    }
                    $update[$column] = $payload[$key];
                }
                if ($update) {
                    DB::table($table)->where('id', $row->id)->update($update);
                }
            }
        });
    }

    private function backfillMovements(): void
    {
        DB::table('stock_movements')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $payload = json_decode($row->payload ?? '[]', true) ?: [];
                $update = [
                    'product_id' => $payload['productId'] ?? null,
                    'type' => $payload['type'] ?? null,
                    'quantity' => $payload['quantity'] ?? null,
                    'from_warehouse_id' => $payload['fromWarehouseId'] ?? null,
                    'to_warehouse_id' => $payload['toWarehouseId'] ?? null,
                    'user_id' => $payload['userId'] ?? null,
                    'note' => $payload['note'] ?? null,
                    'reference' => $payload['reference'] ?? null,
                ];
                if (! empty($payload['date'])) {
                    try {
                        $update['moved_at'] = \Carbon\Carbon::parse($payload['date'])->toDateTimeString();
                    } catch (\Throwable) {
                        $update['moved_at'] = null;
                    }
                }
                DB::table('stock_movements')->where('id', $row->id)->update($update);
            }
        });
    }
};
