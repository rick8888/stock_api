<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
            $table->string('plate')->nullable()->index()->after('name');
            $table->string('status')->nullable()->index()->after('plate');
            $table->string('warehouse_id')->nullable()->index()->after('status');
            $table->string('company_id')->nullable()->index()->after('warehouse_id');
        });

        Schema::table('drivers', function (Blueprint $table) {
            $table->string('user_id')->nullable()->index()->after('id');
            $table->string('vehicle_id')->nullable()->index()->after('user_id');
            $table->string('phone')->nullable()->after('vehicle_id');
            $table->string('status')->nullable()->index()->after('phone');
            $table->string('warehouse_id')->nullable()->index()->after('status');
            $table->string('company_id')->nullable()->index()->after('warehouse_id');
        });

        Schema::table('procurement_requests', function (Blueprint $table) {
            $table->string('code')->nullable()->index()->after('id');
            $table->string('requester_id')->nullable()->index()->after('code');
            $table->string('warehouse_id')->nullable()->index()->after('requester_id');
            $table->string('status')->nullable()->index()->after('warehouse_id');
            $table->string('priority')->nullable()->index()->after('status');
            $table->string('service')->nullable()->after('priority');
            $table->timestamp('requested_at')->nullable()->index()->after('service');
            $table->timestamp('submitted_at')->nullable()->after('requested_at');
            $table->timestamp('decided_at')->nullable()->after('submitted_at');
            $table->text('decision_note')->nullable()->after('decided_at');
            $table->text('note')->nullable()->after('decision_note');
            $table->string('assignee_id')->nullable()->index()->after('note');
            $table->string('assigned_role')->nullable()->after('assignee_id');
            $table->timestamp('handed_off_at')->nullable()->after('assigned_role');
            $table->timestamp('taken_at')->nullable()->after('handed_off_at');
            $table->string('company_id')->nullable()->index()->after('taken_at');
        });

        Schema::create('procurement_request_items', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('procurement_request_id')->index();
            $table->string('product_id')->index();
            $table->integer('quantity')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('code')->nullable()->index()->after('id');
            $table->string('supplier_id')->nullable()->index()->after('code');
            $table->string('warehouse_id')->nullable()->index()->after('supplier_id');
            $table->string('status')->nullable()->index()->after('warehouse_id');
            $table->date('expected_date')->nullable()->index()->after('status');
            $table->timestamp('ordered_at')->nullable()->index()->after('expected_date');
            $table->decimal('amount', 14, 2)->nullable()->after('ordered_at');
            $table->string('procurement_request_id')->nullable()->index()->after('amount');
            $table->json('procurement_request_ids')->nullable()->after('procurement_request_id');
            $table->string('consultation_id')->nullable()->index()->after('procurement_request_ids');
            $table->string('quote_id')->nullable()->index()->after('consultation_id');
            $table->string('company_id')->nullable()->index()->after('quote_id');
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('purchase_order_id')->index();
            $table->string('product_id')->index();
            $table->integer('quantity')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('receipts', function (Blueprint $table) {
            $table->string('code')->nullable()->index()->after('id');
            $table->string('purchase_order_id')->nullable()->index()->after('code');
            $table->string('warehouse_id')->nullable()->index()->after('purchase_order_id');
            $table->integer('expected')->nullable()->after('warehouse_id');
            $table->integer('received')->nullable()->after('expected');
            $table->timestamp('received_at')->nullable()->index()->after('received');
            $table->string('status')->nullable()->index()->after('received_at');
            $table->string('user_id')->nullable()->index()->after('status');
            $table->text('note')->nullable()->after('user_id');
            $table->timestamp('checked_at')->nullable()->after('note');
            $table->string('company_id')->nullable()->index()->after('checked_at');
        });

        Schema::create('receipt_items', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('receipt_id')->index();
            $table->string('product_id')->index();
            $table->integer('expected')->default(0);
            $table->integer('received')->default(0);
            $table->string('lot_code')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('location_id')->nullable()->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $this->backfillSimple('vehicles', [
            'name' => 'name',
            'plate' => 'plate',
            'status' => 'status',
            'warehouse_id' => 'warehouseId',
            'company_id' => 'companyId',
        ]);
        $this->backfillSimple('drivers', [
            'user_id' => 'userId',
            'vehicle_id' => 'vehicleId',
            'phone' => 'phone',
            'status' => 'status',
            'warehouse_id' => 'warehouseId',
            'company_id' => 'companyId',
        ]);
        $this->backfillProcurement();
        $this->backfillPurchaseOrders();
        $this->backfillReceipts();
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_items');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('procurement_request_items');
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropColumn([
                'code', 'purchase_order_id', 'warehouse_id', 'expected', 'received', 'received_at',
                'status', 'user_id', 'note', 'checked_at', 'company_id',
            ]);
        });
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn([
                'code', 'supplier_id', 'warehouse_id', 'status', 'expected_date', 'ordered_at', 'amount',
                'procurement_request_id', 'procurement_request_ids', 'consultation_id', 'quote_id', 'company_id',
            ]);
        });
        Schema::table('procurement_requests', function (Blueprint $table) {
            $table->dropColumn([
                'code', 'requester_id', 'warehouse_id', 'status', 'priority', 'service', 'requested_at',
                'submitted_at', 'decided_at', 'decision_note', 'note', 'assignee_id', 'assigned_role',
                'handed_off_at', 'taken_at', 'company_id',
            ]);
        });
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn(['user_id', 'vehicle_id', 'phone', 'status', 'warehouse_id', 'company_id']);
        });
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['name', 'plate', 'status', 'warehouse_id', 'company_id']);
        });
    }

    /** @param array<string, string> $map */
    private function backfillSimple(string $table, array $map): void
    {
        DB::table($table)->orderBy('id')->chunkById(200, function ($rows) use ($table, $map) {
            foreach ($rows as $row) {
                $payload = json_decode($row->payload ?? '[]', true) ?: [];
                $update = [];
                foreach ($map as $column => $key) {
                    if (array_key_exists($key, $payload)) {
                        $update[$column] = $payload[$key];
                    }
                }
                if ($update) {
                    DB::table($table)->where('id', $row->id)->update($update);
                }
            }
        });
    }

    private function backfillProcurement(): void
    {
        DB::table('procurement_requests')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $payload = json_decode($row->payload ?? '[]', true) ?: [];
                $update = [
                    'code' => $payload['code'] ?? null,
                    'requester_id' => $payload['requesterId'] ?? null,
                    'warehouse_id' => $payload['warehouseId'] ?? null,
                    'status' => $payload['status'] ?? null,
                    'priority' => $payload['priority'] ?? null,
                    'service' => $payload['service'] ?? null,
                    'decision_note' => $payload['decisionNote'] ?? null,
                    'note' => $payload['note'] ?? null,
                    'assignee_id' => $payload['assigneeId'] ?? null,
                    'assigned_role' => $payload['assignedRole'] ?? null,
                    'company_id' => $payload['companyId'] ?? null,
                ];
                foreach ([
                    'createdAt' => 'requested_at',
                    'submittedAt' => 'submitted_at',
                    'decidedAt' => 'decided_at',
                    'handedOffAt' => 'handed_off_at',
                    'takenAt' => 'taken_at',
                ] as $from => $to) {
                    if (! empty($payload[$from])) {
                        try {
                            $update[$to] = \Carbon\Carbon::parse($payload[$from])->toDateTimeString();
                        } catch (\Throwable) {
                            $update[$to] = null;
                        }
                    }
                }
                DB::table('procurement_requests')->where('id', $row->id)->update($update);
                foreach (array_values($payload['items'] ?? []) as $index => $item) {
                    if (! is_array($item) || empty($item['productId'])) {
                        continue;
                    }
                    DB::table('procurement_request_items')->insert([
                        'id' => 'pri-'.substr(md5($row->id.'|'.$index.'|'.$item['productId']), 0, 12),
                        'procurement_request_id' => $row->id,
                        'product_id' => $item['productId'],
                        'quantity' => (int) ($item['quantity'] ?? 0),
                        'sort_order' => $index,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    private function backfillPurchaseOrders(): void
    {
        DB::table('purchase_orders')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $payload = json_decode($row->payload ?? '[]', true) ?: [];
                $update = [
                    'code' => $payload['code'] ?? null,
                    'supplier_id' => $payload['supplierId'] ?? null,
                    'warehouse_id' => $payload['warehouseId'] ?? null,
                    'status' => $payload['status'] ?? null,
                    'amount' => $payload['amount'] ?? null,
                    'procurement_request_id' => $payload['procurementRequestId'] ?? null,
                    'procurement_request_ids' => isset($payload['procurementRequestIds'])
                        ? json_encode(array_values($payload['procurementRequestIds']))
                        : null,
                    'consultation_id' => $payload['consultationId'] ?? null,
                    'quote_id' => $payload['quoteId'] ?? null,
                    'company_id' => $payload['companyId'] ?? null,
                ];
                if (! empty($payload['expectedDate'])) {
                    try {
                        $update['expected_date'] = \Carbon\Carbon::parse($payload['expectedDate'])->toDateString();
                    } catch (\Throwable) {
                        $update['expected_date'] = null;
                    }
                }
                if (! empty($payload['createdAt'])) {
                    try {
                        $update['ordered_at'] = \Carbon\Carbon::parse($payload['createdAt'])->toDateTimeString();
                    } catch (\Throwable) {
                        $update['ordered_at'] = null;
                    }
                }
                DB::table('purchase_orders')->where('id', $row->id)->update($update);
                foreach (array_values($payload['items'] ?? []) as $index => $item) {
                    if (! is_array($item) || empty($item['productId'])) {
                        continue;
                    }
                    DB::table('purchase_order_items')->insert([
                        'id' => 'poi-'.substr(md5($row->id.'|'.$index.'|'.$item['productId']), 0, 12),
                        'purchase_order_id' => $row->id,
                        'product_id' => $item['productId'],
                        'quantity' => (int) ($item['quantity'] ?? 0),
                        'sort_order' => $index,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    private function backfillReceipts(): void
    {
        DB::table('receipts')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $payload = json_decode($row->payload ?? '[]', true) ?: [];
                $update = [
                    'code' => $payload['code'] ?? null,
                    'purchase_order_id' => $payload['purchaseOrderId'] ?? null,
                    'warehouse_id' => $payload['warehouseId'] ?? null,
                    'expected' => $payload['expected'] ?? null,
                    'received' => $payload['received'] ?? null,
                    'status' => $payload['status'] ?? null,
                    'user_id' => $payload['userId'] ?? null,
                    'note' => $payload['note'] ?? null,
                    'company_id' => $payload['companyId'] ?? null,
                ];
                foreach (['date' => 'received_at', 'checkedAt' => 'checked_at'] as $from => $to) {
                    if (! empty($payload[$from])) {
                        try {
                            $update[$to] = \Carbon\Carbon::parse($payload[$from])->toDateTimeString();
                        } catch (\Throwable) {
                            $update[$to] = null;
                        }
                    }
                }
                DB::table('receipts')->where('id', $row->id)->update($update);
                foreach (array_values($payload['items'] ?? []) as $index => $item) {
                    if (! is_array($item) || empty($item['productId'])) {
                        continue;
                    }
                    $line = [
                        'id' => 'rci-'.substr(md5($row->id.'|'.$index.'|'.$item['productId']), 0, 12),
                        'receipt_id' => $row->id,
                        'product_id' => $item['productId'],
                        'expected' => (int) ($item['expected'] ?? 0),
                        'received' => (int) ($item['received'] ?? 0),
                        'lot_code' => $item['lotCode'] ?? null,
                        'location_id' => $item['locationId'] ?? null,
                        'sort_order' => $index,
                        'created_at' => now(),
                        'updated_at' => now(),
                        'expiry_date' => null,
                    ];
                    if (! empty($item['expiryDate'])) {
                        try {
                            $line['expiry_date'] = \Carbon\Carbon::parse($item['expiryDate'])->toDateString();
                        } catch (\Throwable) {
                            $line['expiry_date'] = null;
                        }
                    }
                    DB::table('receipt_items')->insert($line);
                }
            }
        });
    }
};
