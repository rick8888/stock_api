<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_locations', function (Blueprint $table) {
            $table->string('warehouse_id')->nullable()->index()->after('id');
            $table->string('code')->nullable()->after('warehouse_id');
            $table->string('aisle')->nullable()->index()->after('code');
            $table->string('rack')->nullable()->after('aisle');
            $table->string('bin')->nullable()->after('rack');
            $table->string('type')->nullable()->index()->after('bin');
            $table->boolean('active')->nullable()->index()->after('type');
            $table->string('company_id')->nullable()->index()->after('active');
            $table->unique(['warehouse_id', 'code'], 'warehouse_locations_warehouse_code_unique');
        });

        Schema::table('stock_lots', function (Blueprint $table) {
            $table->string('product_id')->nullable()->index()->after('id');
            $table->string('warehouse_id')->nullable()->index()->after('product_id');
            $table->string('lot_code')->nullable()->after('warehouse_id');
            $table->date('expiry_date')->nullable()->index()->after('lot_code');
            $table->string('location_id')->nullable()->index()->after('expiry_date');
            $table->integer('quantity')->nullable()->after('location_id');
            $table->string('company_id')->nullable()->index()->after('quantity');
            $table->unique(['product_id', 'warehouse_id', 'lot_code'], 'stock_lots_product_warehouse_code_unique');
        });

        Schema::table('stock_placements', function (Blueprint $table) {
            $table->string('product_id')->nullable()->index()->after('id');
            $table->string('warehouse_id')->nullable()->index()->after('product_id');
            $table->string('location_id')->nullable()->index()->after('warehouse_id');
            $table->string('lot_id')->nullable()->index()->after('location_id');
            $table->integer('quantity')->nullable()->after('lot_id');
            $table->string('company_id')->nullable()->index()->after('quantity');
            $table->index(['product_id', 'warehouse_id', 'location_id'], 'stock_placements_product_wh_loc_idx');
        });

        $this->backfill('warehouse_locations', [
            'warehouse_id' => 'warehouseId',
            'code' => 'code',
            'aisle' => 'aisle',
            'rack' => 'rack',
            'bin' => 'bin',
            'type' => 'type',
            'active' => 'active',
            'company_id' => 'companyId',
        ]);
        $this->backfillLots();
        $this->backfill('stock_placements', [
            'product_id' => 'productId',
            'warehouse_id' => 'warehouseId',
            'location_id' => 'locationId',
            'lot_id' => 'lotId',
            'quantity' => 'quantity',
            'company_id' => 'companyId',
        ]);
    }

    public function down(): void
    {
        Schema::table('stock_placements', function (Blueprint $table) {
            $table->dropIndex('stock_placements_product_wh_loc_idx');
            $table->dropColumn(['product_id', 'warehouse_id', 'location_id', 'lot_id', 'quantity', 'company_id']);
        });
        Schema::table('stock_lots', function (Blueprint $table) {
            $table->dropUnique('stock_lots_product_warehouse_code_unique');
            $table->dropColumn([
                'product_id', 'warehouse_id', 'lot_code', 'expiry_date', 'location_id', 'quantity', 'company_id',
            ]);
        });
        Schema::table('warehouse_locations', function (Blueprint $table) {
            $table->dropUnique('warehouse_locations_warehouse_code_unique');
            $table->dropColumn([
                'warehouse_id', 'code', 'aisle', 'rack', 'bin', 'type', 'active', 'company_id',
            ]);
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

    private function backfillLots(): void
    {
        DB::table('stock_lots')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $payload = json_decode($row->payload ?? '[]', true) ?: [];
                $update = [
                    'product_id' => $payload['productId'] ?? null,
                    'warehouse_id' => $payload['warehouseId'] ?? null,
                    'lot_code' => $payload['lotCode'] ?? null,
                    'location_id' => $payload['locationId'] ?? null,
                    'quantity' => $payload['quantity'] ?? null,
                    'company_id' => $payload['companyId'] ?? null,
                ];
                if (! empty($payload['expiryDate'])) {
                    try {
                        $update['expiry_date'] = \Carbon\Carbon::parse($payload['expiryDate'])->toDateString();
                    } catch (\Throwable) {
                        $update['expiry_date'] = null;
                    }
                }
                DB::table('stock_lots')->where('id', $row->id)->update($update);
            }
        });
    }
};
