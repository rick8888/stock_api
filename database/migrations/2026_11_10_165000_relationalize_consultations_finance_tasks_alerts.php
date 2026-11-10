<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->string('code')->nullable()->index()->after('id');
            $table->string('status')->nullable()->index()->after('code');
            $table->string('warehouse_id')->nullable()->index()->after('status');
            $table->json('procurement_request_ids')->nullable()->after('warehouse_id');
            $table->json('supplier_ids')->nullable()->after('procurement_request_ids');
            $table->json('quotes')->nullable()->after('supplier_ids');
            $table->timestamp('consulted_at')->nullable()->index()->after('quotes');
            $table->timestamp('sent_at')->nullable()->after('consulted_at');
            $table->timestamp('quoted_at')->nullable()->after('sent_at');
            $table->timestamp('awarded_at')->nullable()->after('quoted_at');
            $table->string('awarded_quote_id')->nullable()->index()->after('awarded_at');
            $table->text('note')->nullable()->after('awarded_quote_id');
            $table->string('company_id')->nullable()->index()->after('note');
        });

        Schema::create('consultation_items', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('consultation_id')->index();
            $table->string('product_id')->index();
            $table->integer('quantity')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('supplier_id')->nullable()->index()->after('id');
            $table->string('purchase_order_id')->nullable()->index()->after('supplier_id');
            $table->decimal('amount', 14, 2)->nullable()->after('purchase_order_id');
            $table->string('status')->nullable()->index()->after('amount');
            $table->date('due_date')->nullable()->index()->after('status');
            $table->string('company_id')->nullable()->index()->after('due_date');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('invoice_id')->nullable()->index()->after('id');
            $table->decimal('amount', 14, 2)->nullable()->after('invoice_id');
            $table->timestamp('paid_at')->nullable()->index()->after('amount');
            $table->string('company_id')->nullable()->index()->after('paid_at');
        });

        Schema::table('budgets', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
            $table->string('period')->nullable()->index()->after('name');
            $table->decimal('allocated', 14, 2)->nullable()->after('period');
            $table->decimal('spent', 14, 2)->nullable()->after('allocated');
            $table->string('company_id')->nullable()->index()->after('spent');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->string('label')->nullable()->after('id');
            $table->decimal('amount', 14, 2)->nullable()->after('label');
            $table->timestamp('spent_at')->nullable()->index()->after('amount');
            $table->string('category')->nullable()->index()->after('spent_at');
            $table->string('company_id')->nullable()->index()->after('category');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->string('title')->nullable()->after('id');
            $table->text('description')->nullable()->after('title');
            $table->string('assignee_id')->nullable()->index()->after('description');
            $table->timestamp('due_at')->nullable()->index()->after('assignee_id');
            $table->string('status')->nullable()->index()->after('due_at');
            $table->string('priority')->nullable()->index()->after('status');
            $table->string('related_type')->nullable()->index()->after('priority');
            $table->string('related_id')->nullable()->index()->after('related_type');
            $table->string('warehouse_id')->nullable()->index()->after('related_id');
            $table->string('company_id')->nullable()->index()->after('warehouse_id');
        });

        Schema::table('alerts', function (Blueprint $table) {
            $table->string('title')->nullable()->after('id');
            $table->text('message')->nullable()->after('title');
            $table->string('severity')->nullable()->index()->after('message');
            $table->timestamp('alerted_at')->nullable()->index()->after('severity');
            $table->boolean('read')->nullable()->index()->after('alerted_at');
            $table->string('domain')->nullable()->index()->after('read');
            $table->string('related_id')->nullable()->index()->after('domain');
            $table->string('warehouse_id')->nullable()->index()->after('related_id');
            $table->string('user_id')->nullable()->index()->after('warehouse_id');
            $table->json('roles')->nullable()->after('user_id');
            $table->string('company_id')->nullable()->index()->after('roles');
        });

        $this->backfillConsultations();
        $this->backfillSimple('invoices', [
            'supplier_id' => 'supplierId',
            'purchase_order_id' => 'purchaseOrderId',
            'amount' => 'amount',
            'status' => 'status',
            'company_id' => 'companyId',
        ], ['dueDate' => 'due_date']);
        $this->backfillPayments();
        $this->backfillSimple('budgets', [
            'name' => 'name',
            'period' => 'period',
            'allocated' => 'allocated',
            'spent' => 'spent',
            'company_id' => 'companyId',
        ]);
        $this->backfillExpenses();
        $this->backfillTasks();
        $this->backfillAlerts();
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_items');
        foreach ([
            'alerts' => ['title', 'message', 'severity', 'alerted_at', 'read', 'domain', 'related_id', 'warehouse_id', 'user_id', 'roles', 'company_id'],
            'tasks' => ['title', 'description', 'assignee_id', 'due_at', 'status', 'priority', 'related_type', 'related_id', 'warehouse_id', 'company_id'],
            'expenses' => ['label', 'amount', 'spent_at', 'category', 'company_id'],
            'budgets' => ['name', 'period', 'allocated', 'spent', 'company_id'],
            'payments' => ['invoice_id', 'amount', 'paid_at', 'company_id'],
            'invoices' => ['supplier_id', 'purchase_order_id', 'amount', 'status', 'due_date', 'company_id'],
            'consultations' => [
                'code', 'status', 'warehouse_id', 'procurement_request_ids', 'supplier_ids', 'quotes',
                'consulted_at', 'sent_at', 'quoted_at', 'awarded_at', 'awarded_quote_id', 'note', 'company_id',
            ],
        ] as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                $blueprint->dropColumn($columns);
            });
        }
    }

    /** @param array<string, string> $map */
    private function backfillSimple(string $table, array $map, array $dates = []): void
    {
        DB::table($table)->orderBy('id')->chunkById(200, function ($rows) use ($table, $map, $dates) {
            foreach ($rows as $row) {
                $payload = json_decode($row->payload ?? '[]', true) ?: [];
                $update = [];
                foreach ($map as $column => $key) {
                    if (array_key_exists($key, $payload)) {
                        $update[$column] = $payload[$key];
                    }
                }
                foreach ($dates as $from => $to) {
                    if (! empty($payload[$from])) {
                        try {
                            $update[$to] = str_contains($to, 'date') && ! str_contains($to, 'at')
                                ? \Carbon\Carbon::parse($payload[$from])->toDateString()
                                : \Carbon\Carbon::parse($payload[$from])->toDateTimeString();
                        } catch (\Throwable) {
                            $update[$to] = null;
                        }
                    }
                }
                if ($update) {
                    DB::table($table)->where('id', $row->id)->update($update);
                }
            }
        });
    }

    private function backfillConsultations(): void
    {
        DB::table('consultations')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $payload = json_decode($row->payload ?? '[]', true) ?: [];
                $update = [
                    'code' => $payload['code'] ?? null,
                    'status' => $payload['status'] ?? null,
                    'warehouse_id' => $payload['warehouseId'] ?? null,
                    'procurement_request_ids' => isset($payload['procurementRequestIds']) ? json_encode(array_values($payload['procurementRequestIds'])) : null,
                    'supplier_ids' => isset($payload['supplierIds']) ? json_encode(array_values($payload['supplierIds'])) : null,
                    'quotes' => isset($payload['quotes']) ? json_encode($payload['quotes']) : null,
                    'awarded_quote_id' => $payload['awardedQuoteId'] ?? null,
                    'note' => $payload['note'] ?? null,
                    'company_id' => $payload['companyId'] ?? null,
                ];
                foreach ([
                    'createdAt' => 'consulted_at',
                    'sentAt' => 'sent_at',
                    'quotedAt' => 'quoted_at',
                    'awardedAt' => 'awarded_at',
                ] as $from => $to) {
                    if (! empty($payload[$from])) {
                        try {
                            $update[$to] = \Carbon\Carbon::parse($payload[$from])->toDateTimeString();
                        } catch (\Throwable) {
                            $update[$to] = null;
                        }
                    }
                }
                DB::table('consultations')->where('id', $row->id)->update($update);
                foreach (array_values($payload['items'] ?? []) as $index => $item) {
                    if (! is_array($item) || empty($item['productId'])) {
                        continue;
                    }
                    DB::table('consultation_items')->insert([
                        'id' => 'csi-'.substr(md5($row->id.'|'.$index.'|'.$item['productId']), 0, 12),
                        'consultation_id' => $row->id,
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

    private function backfillPayments(): void
    {
        DB::table('payments')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $payload = json_decode($row->payload ?? '[]', true) ?: [];
                $update = [
                    'invoice_id' => $payload['invoiceId'] ?? null,
                    'amount' => $payload['amount'] ?? null,
                    'company_id' => $payload['companyId'] ?? null,
                ];
                if (! empty($payload['date'])) {
                    try {
                        $update['paid_at'] = \Carbon\Carbon::parse($payload['date'])->toDateTimeString();
                    } catch (\Throwable) {
                        $update['paid_at'] = null;
                    }
                }
                DB::table('payments')->where('id', $row->id)->update($update);
            }
        });
    }

    private function backfillExpenses(): void
    {
        DB::table('expenses')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $payload = json_decode($row->payload ?? '[]', true) ?: [];
                $update = [
                    'label' => $payload['label'] ?? null,
                    'amount' => $payload['amount'] ?? null,
                    'category' => $payload['category'] ?? null,
                    'company_id' => $payload['companyId'] ?? null,
                ];
                if (! empty($payload['date'])) {
                    try {
                        $update['spent_at'] = \Carbon\Carbon::parse($payload['date'])->toDateTimeString();
                    } catch (\Throwable) {
                        $update['spent_at'] = null;
                    }
                }
                DB::table('expenses')->where('id', $row->id)->update($update);
            }
        });
    }

    private function backfillTasks(): void
    {
        DB::table('tasks')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $payload = json_decode($row->payload ?? '[]', true) ?: [];
                $update = [
                    'title' => $payload['title'] ?? null,
                    'description' => $payload['description'] ?? null,
                    'assignee_id' => $payload['assigneeId'] ?? null,
                    'status' => $payload['status'] ?? null,
                    'priority' => $payload['priority'] ?? null,
                    'related_type' => $payload['relatedType'] ?? null,
                    'related_id' => $payload['relatedId'] ?? null,
                    'warehouse_id' => $payload['warehouseId'] ?? null,
                    'company_id' => $payload['companyId'] ?? null,
                ];
                if (! empty($payload['dueAt'])) {
                    try {
                        $update['due_at'] = \Carbon\Carbon::parse($payload['dueAt'])->toDateTimeString();
                    } catch (\Throwable) {
                        $update['due_at'] = null;
                    }
                }
                DB::table('tasks')->where('id', $row->id)->update($update);
            }
        });
    }

    private function backfillAlerts(): void
    {
        DB::table('alerts')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $payload = json_decode($row->payload ?? '[]', true) ?: [];
                $update = [
                    'title' => $payload['title'] ?? null,
                    'message' => $payload['message'] ?? null,
                    'severity' => $payload['severity'] ?? null,
                    'read' => $payload['read'] ?? null,
                    'domain' => $payload['domain'] ?? null,
                    'related_id' => $payload['relatedId'] ?? null,
                    'warehouse_id' => $payload['warehouseId'] ?? null,
                    'user_id' => $payload['userId'] ?? null,
                    'roles' => isset($payload['roles']) ? json_encode(array_values($payload['roles'])) : null,
                    'company_id' => $payload['companyId'] ?? null,
                ];
                if (! empty($payload['createdAt'])) {
                    try {
                        $update['alerted_at'] = \Carbon\Carbon::parse($payload['createdAt'])->toDateTimeString();
                    } catch (\Throwable) {
                        $update['alerted_at'] = null;
                    }
                }
                DB::table('alerts')->where('id', $row->id)->update($update);
            }
        });
    }
};
