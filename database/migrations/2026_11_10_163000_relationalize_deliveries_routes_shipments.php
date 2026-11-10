<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->string('code')->nullable()->index()->after('id');
            $table->string('client')->nullable()->after('code');
            $table->string('address')->nullable()->after('client');
            $table->string('city')->nullable()->index()->after('address');
            $table->timestamp('scheduled_at')->nullable()->index()->after('city');
            $table->string('status')->nullable()->index()->after('scheduled_at');
            $table->string('driver_id')->nullable()->index()->after('status');
            $table->string('vehicle_id')->nullable()->index()->after('driver_id');
            $table->string('route_id')->nullable()->index()->after('vehicle_id');
            $table->unsignedInteger('sequence')->nullable()->after('route_id');
            $table->string('warehouse_id')->nullable()->index()->after('sequence');
            $table->string('shipping_note_id')->nullable()->index()->after('warehouse_id');
            $table->unsignedTinyInteger('progress')->nullable()->after('shipping_note_id');
            $table->string('signed_by')->nullable()->after('progress');
            $table->timestamp('signed_at')->nullable()->after('signed_by');
            $table->text('notes')->nullable()->after('signed_at');
            $table->string('company_id')->nullable()->index()->after('notes');
            $table->timestamp('eta_at')->nullable()->after('company_id');
        });

        Schema::create('delivery_lines', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('delivery_id')->index();
            $table->string('product_id')->index();
            $table->string('product_name')->nullable();
            $table->string('sku')->nullable();
            $table->integer('quantity')->default(0);
            $table->string('unit')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('routes', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
            $table->date('route_date')->nullable()->index()->after('name');
            $table->string('driver_id')->nullable()->index()->after('route_date');
            $table->string('vehicle_id')->nullable()->index()->after('driver_id');
            $table->string('warehouse_id')->nullable()->index()->after('vehicle_id');
            $table->string('status')->nullable()->index()->after('warehouse_id');
            $table->decimal('distance_km', 10, 2)->nullable()->after('status');
            $table->unsignedTinyInteger('progress')->nullable()->after('distance_km');
            $table->string('company_id')->nullable()->index()->after('progress');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->string('code')->nullable()->index()->after('id');
            $table->string('origin_warehouse_id')->nullable()->index()->after('code');
            $table->string('destination')->nullable()->after('origin_warehouse_id');
            $table->string('destination_warehouse_id')->nullable()->index()->after('destination');
            $table->string('vehicle_id')->nullable()->index()->after('destination_warehouse_id');
            $table->string('driver_id')->nullable()->index()->after('vehicle_id');
            $table->string('route_id')->nullable()->index()->after('driver_id');
            $table->timestamp('shipped_at')->nullable()->index()->after('route_id');
            $table->string('status')->nullable()->index()->after('shipped_at');
            $table->unsignedTinyInteger('progress')->nullable()->after('status');
            $table->json('shipping_note_ids')->nullable()->after('progress');
            $table->text('notes')->nullable()->after('shipping_note_ids');
            $table->string('company_id')->nullable()->index()->after('notes');
            $table->timestamp('eta_at')->nullable()->after('company_id');
        });

        Schema::create('shipment_lines', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('shipment_id')->index();
            $table->string('product_id')->index();
            $table->string('product_name')->nullable();
            $table->string('sku')->nullable();
            $table->integer('quantity')->default(0);
            $table->string('unit')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $this->backfillDeliveries();
        $this->backfillRoutes();
        $this->backfillShipments();
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_lines');
        Schema::dropIfExists('delivery_lines');
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn([
                'code', 'origin_warehouse_id', 'destination', 'destination_warehouse_id', 'vehicle_id',
                'driver_id', 'route_id', 'shipped_at', 'status', 'progress', 'shipping_note_ids',
                'notes', 'company_id', 'eta_at',
            ]);
        });
        Schema::table('routes', function (Blueprint $table) {
            $table->dropColumn([
                'name', 'route_date', 'driver_id', 'vehicle_id', 'warehouse_id', 'status',
                'distance_km', 'progress', 'company_id',
            ]);
        });
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn([
                'code', 'client', 'address', 'city', 'scheduled_at', 'status', 'driver_id', 'vehicle_id',
                'route_id', 'sequence', 'warehouse_id', 'shipping_note_id', 'progress', 'signed_by',
                'signed_at', 'notes', 'company_id', 'eta_at',
            ]);
        });
    }

    private function backfillDeliveries(): void
    {
        DB::table('deliveries')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $payload = json_decode($row->payload ?? '[]', true) ?: [];
                $update = [
                    'code' => $payload['code'] ?? null,
                    'client' => $payload['client'] ?? null,
                    'address' => $payload['address'] ?? null,
                    'city' => $payload['city'] ?? null,
                    'status' => $payload['status'] ?? null,
                    'driver_id' => $payload['driverId'] ?? null,
                    'vehicle_id' => $payload['vehicleId'] ?? null,
                    'route_id' => $payload['routeId'] ?? null,
                    'sequence' => $payload['sequence'] ?? null,
                    'warehouse_id' => $payload['warehouseId'] ?? null,
                    'shipping_note_id' => $payload['shippingNoteId'] ?? null,
                    'progress' => $payload['progress'] ?? null,
                    'signed_by' => $payload['signedBy'] ?? null,
                    'notes' => $payload['notes'] ?? null,
                    'company_id' => $payload['companyId'] ?? null,
                ];
                foreach (['scheduledAt' => 'scheduled_at', 'signedAt' => 'signed_at', 'eta' => 'eta_at'] as $from => $to) {
                    if (! empty($payload[$from])) {
                        try {
                            $update[$to] = \Carbon\Carbon::parse($payload[$from])->toDateTimeString();
                        } catch (\Throwable) {
                            $update[$to] = null;
                        }
                    }
                }
                DB::table('deliveries')->where('id', $row->id)->update($update);
                $this->insertLines('delivery_lines', 'delivery_id', $row->id, $payload['lines'] ?? []);
            }
        });
    }

    private function backfillRoutes(): void
    {
        DB::table('routes')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $payload = json_decode($row->payload ?? '[]', true) ?: [];
                $update = [
                    'name' => $payload['name'] ?? null,
                    'driver_id' => $payload['driverId'] ?? null,
                    'vehicle_id' => $payload['vehicleId'] ?? null,
                    'warehouse_id' => $payload['warehouseId'] ?? null,
                    'status' => $payload['status'] ?? null,
                    'distance_km' => $payload['distanceKm'] ?? null,
                    'progress' => $payload['progress'] ?? null,
                    'company_id' => $payload['companyId'] ?? null,
                ];
                if (! empty($payload['date'])) {
                    try {
                        $update['route_date'] = \Carbon\Carbon::parse($payload['date'])->toDateString();
                    } catch (\Throwable) {
                        $update['route_date'] = null;
                    }
                }
                DB::table('routes')->where('id', $row->id)->update($update);
            }
        });
    }

    private function backfillShipments(): void
    {
        DB::table('shipments')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $payload = json_decode($row->payload ?? '[]', true) ?: [];
                $update = [
                    'code' => $payload['code'] ?? null,
                    'origin_warehouse_id' => $payload['originWarehouseId'] ?? null,
                    'destination' => $payload['destination'] ?? null,
                    'destination_warehouse_id' => $payload['destinationWarehouseId'] ?? null,
                    'vehicle_id' => $payload['vehicleId'] ?? null,
                    'driver_id' => $payload['driverId'] ?? null,
                    'route_id' => $payload['routeId'] ?? null,
                    'status' => $payload['status'] ?? null,
                    'progress' => $payload['progress'] ?? null,
                    'shipping_note_ids' => isset($payload['shippingNoteIds']) ? json_encode(array_values($payload['shippingNoteIds'])) : null,
                    'notes' => $payload['notes'] ?? null,
                    'company_id' => $payload['companyId'] ?? null,
                ];
                foreach (['date' => 'shipped_at', 'eta' => 'eta_at'] as $from => $to) {
                    if (! empty($payload[$from])) {
                        try {
                            $update[$to] = \Carbon\Carbon::parse($payload[$from])->toDateTimeString();
                        } catch (\Throwable) {
                            $update[$to] = null;
                        }
                    }
                }
                DB::table('shipments')->where('id', $row->id)->update($update);
                $this->insertLines('shipment_lines', 'shipment_id', $row->id, $payload['lines'] ?? []);
            }
        });
    }

    private function insertLines(string $table, string $fk, string $parentId, array $lines): void
    {
        foreach (array_values($lines) as $index => $line) {
            if (! is_array($line) || empty($line['productId'])) {
                continue;
            }
            DB::table($table)->insert([
                'id' => substr($table, 0, 3).'-'.substr(md5($parentId.'|'.$index.'|'.$line['productId']), 0, 12),
                $fk => $parentId,
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
};
