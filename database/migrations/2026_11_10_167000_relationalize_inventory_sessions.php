<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_sessions', function (Blueprint $table) {
            $table->string('warehouse_id')->nullable()->index()->after('id');
            $table->string('status')->nullable()->index()->after('warehouse_id');
            $table->string('mode')->nullable()->index()->after('status');
            $table->json('scope')->nullable()->after('mode');
            $table->unsignedInteger('counted')->nullable()->after('scope');
            $table->unsignedInteger('expected')->nullable()->after('counted');
            $table->timestamp('started_at')->nullable()->index()->after('expected');
            $table->timestamp('completed_at')->nullable()->index()->after('started_at');
            $table->string('user_id')->nullable()->index()->after('completed_at');
            $table->string('counted_by_user_id')->nullable()->index()->after('user_id');
            $table->string('approved_by_user_id')->nullable()->index()->after('counted_by_user_id');
            $table->string('company_id')->nullable()->index()->after('approved_by_user_id');
        });

        Schema::create('inventory_session_lines', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('inventory_session_id')->index();
            $table->string('product_id')->index();
            $table->integer('expected')->default(0);
            $table->integer('counted')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::table('inventory_sessions')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $payload = json_decode($row->payload ?? '[]', true) ?: [];
                $update = [
                    'warehouse_id' => $payload['warehouseId'] ?? null,
                    'status' => $payload['status'] ?? null,
                    'mode' => $payload['mode'] ?? null,
                    'scope' => isset($payload['scope']) ? json_encode($payload['scope']) : null,
                    'counted' => $payload['counted'] ?? null,
                    'expected' => $payload['expected'] ?? null,
                    'user_id' => $payload['userId'] ?? null,
                    'counted_by_user_id' => $payload['countedByUserId'] ?? null,
                    'approved_by_user_id' => $payload['approvedByUserId'] ?? null,
                    'company_id' => $payload['companyId'] ?? null,
                ];
                foreach (['startedAt' => 'started_at', 'completedAt' => 'completed_at'] as $from => $to) {
                    if (! empty($payload[$from])) {
                        try {
                            $update[$to] = \Carbon\Carbon::parse($payload[$from])->toDateTimeString();
                        } catch (\Throwable) {
                            $update[$to] = null;
                        }
                    }
                }
                DB::table('inventory_sessions')->where('id', $row->id)->update($update);
                foreach (array_values($payload['lines'] ?? []) as $index => $line) {
                    if (! is_array($line) || empty($line['productId'])) {
                        continue;
                    }
                    DB::table('inventory_session_lines')->insert([
                        'id' => 'inl-'.substr(md5($row->id.'|'.$index.'|'.$line['productId']), 0, 12),
                        'inventory_session_id' => $row->id,
                        'product_id' => $line['productId'],
                        'expected' => (int) ($line['expected'] ?? 0),
                        'counted' => (int) ($line['counted'] ?? 0),
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
        Schema::dropIfExists('inventory_session_lines');
        Schema::table('inventory_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'warehouse_id', 'status', 'mode', 'scope', 'counted', 'expected',
                'started_at', 'completed_at', 'user_id', 'counted_by_user_id',
                'approved_by_user_id', 'company_id',
            ]);
        });
    }
};
