<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_notes', function (Blueprint $table) {
            $table->string('code')->nullable()->index()->after('id');
            $table->string('warehouse_id')->nullable()->index()->after('code');
            $table->string('customer_id')->nullable()->index()->after('warehouse_id');
            $table->string('address_id')->nullable()->after('customer_id');
            $table->string('client')->nullable()->after('address_id');
            $table->string('address')->nullable()->after('client');
            $table->string('city')->nullable()->index()->after('address');
            $table->string('status')->nullable()->index()->after('city');
            $table->string('delivery_id')->nullable()->index()->after('status');
            $table->string('shipment_id')->nullable()->index()->after('delivery_id');
            $table->string('carrier_id')->nullable()->index()->after('shipment_id');
            $table->string('carrier_name')->nullable()->after('carrier_id');
            $table->string('tracking_number')->nullable()->index()->after('carrier_name');
            $table->timestamp('label_printed_at')->nullable()->after('tracking_number');
            $table->timestamp('booked_at')->nullable()->after('label_printed_at');
            $table->timestamp('loaded_at')->nullable()->after('booked_at');
            $table->string('loaded_by_user_id')->nullable()->after('loaded_at');
            $table->boolean('stock_reserved')->nullable()->after('loaded_by_user_id');
            $table->string('in_transit_to_warehouse_id')->nullable()->index()->after('stock_reserved');
            $table->timestamp('stock_received_at')->nullable()->after('in_transit_to_warehouse_id');
            $table->string('created_by_user_id')->nullable()->index()->after('stock_received_at');
            $table->string('company_id')->nullable()->index()->after('created_by_user_id');
            $table->text('notes')->nullable()->after('company_id');
        });

        Schema::create('shipping_note_lines', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('shipping_note_id')->index();
            $table->string('product_id')->index();
            $table->string('product_name')->nullable();
            $table->string('sku')->nullable();
            $table->integer('quantity')->default(0);
            $table->string('unit')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::table('shipping_notes')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $payload = json_decode($row->payload ?? '[]', true) ?: [];
                $update = [
                    'code' => $payload['code'] ?? null,
                    'warehouse_id' => $payload['warehouseId'] ?? null,
                    'customer_id' => $payload['customerId'] ?? null,
                    'address_id' => $payload['addressId'] ?? null,
                    'client' => $payload['client'] ?? null,
                    'address' => $payload['address'] ?? null,
                    'city' => $payload['city'] ?? null,
                    'status' => $payload['status'] ?? null,
                    'delivery_id' => $payload['deliveryId'] ?? null,
                    'shipment_id' => $payload['shipmentId'] ?? null,
                    'carrier_id' => $payload['carrierId'] ?? null,
                    'carrier_name' => $payload['carrierName'] ?? null,
                    'tracking_number' => $payload['trackingNumber'] ?? null,
                    'loaded_by_user_id' => $payload['loadedByUserId'] ?? null,
                    'stock_reserved' => $payload['stockReserved'] ?? null,
                    'in_transit_to_warehouse_id' => $payload['inTransitToWarehouseId'] ?? null,
                    'created_by_user_id' => $payload['createdByUserId'] ?? null,
                    'company_id' => $payload['companyId'] ?? null,
                    'notes' => $payload['notes'] ?? null,
                ];
                foreach (['labelPrintedAt' => 'label_printed_at', 'bookedAt' => 'booked_at', 'loadedAt' => 'loaded_at', 'stockReceivedAt' => 'stock_received_at'] as $from => $to) {
                    if (! empty($payload[$from])) {
                        try {
                            $update[$to] = \Carbon\Carbon::parse($payload[$from])->toDateTimeString();
                        } catch (\Throwable) {
                            $update[$to] = null;
                        }
                    }
                }
                DB::table('shipping_notes')->where('id', $row->id)->update($update);

                foreach (array_values($payload['lines'] ?? []) as $index => $line) {
                    if (! is_array($line) || empty($line['productId'])) {
                        continue;
                    }
                    DB::table('shipping_note_lines')->insert([
                        'id' => 'bnl-'.substr(md5($row->id.'|'.$index.'|'.$line['productId']), 0, 12),
                        'shipping_note_id' => $row->id,
                        'product_id' => $line['productId'],
                        'product_name' => $line['productName'] ?? null,
                        'sku' => $line['sku'] ?? null,
                        'quantity' => (int) ($line['quantity'] ?? 0),
                        'unit' => $line['unit'] ?? null,
                        'sort_order' => $index,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_note_lines');
        Schema::table('shipping_notes', function (Blueprint $table) {
            $table->dropColumn([
                'code', 'warehouse_id', 'customer_id', 'address_id', 'client', 'address', 'city', 'status',
                'delivery_id', 'shipment_id', 'carrier_id', 'carrier_name', 'tracking_number',
                'label_printed_at', 'booked_at', 'loaded_at', 'loaded_by_user_id', 'stock_reserved',
                'in_transit_to_warehouse_id', 'stock_received_at', 'created_by_user_id', 'company_id', 'notes',
            ]);
        });
    }
};
