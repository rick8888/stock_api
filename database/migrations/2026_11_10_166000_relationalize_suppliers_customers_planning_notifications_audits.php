<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
            $table->string('contact')->nullable()->after('name');
            $table->string('email')->nullable()->index()->after('contact');
            $table->string('phone')->nullable()->after('email');
            $table->string('city')->nullable()->index()->after('phone');
            $table->text('address')->nullable()->after('city');
            $table->string('ice')->nullable()->after('address');
            $table->string('category')->nullable()->index()->after('ice');
            $table->string('payment_terms')->nullable()->after('category');
            $table->text('note')->nullable()->after('payment_terms');
            $table->unsignedInteger('lead_time_days')->nullable()->after('note');
            $table->unsignedInteger('reliability')->nullable()->after('lead_time_days');
            $table->string('status')->nullable()->index()->after('reliability');
            $table->string('company_id')->nullable()->index()->after('status');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
            $table->string('phone')->nullable()->after('name');
            $table->string('email')->nullable()->index()->after('phone');
            $table->string('ice')->nullable()->after('email');
            $table->text('notes')->nullable()->after('ice');
            $table->boolean('active')->nullable()->index()->after('notes');
            $table->json('addresses')->nullable()->after('active');
            $table->string('company_id')->nullable()->index()->after('addresses');
        });

        Schema::table('planning_events', function (Blueprint $table) {
            $table->string('title')->nullable()->after('id');
            $table->timestamp('event_at')->nullable()->index()->after('title');
            $table->string('type')->nullable()->index()->after('event_at');
            $table->string('related_id')->nullable()->index()->after('type');
            $table->string('warehouse_id')->nullable()->index()->after('related_id');
            $table->string('company_id')->nullable()->index()->after('warehouse_id');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->string('title')->nullable()->after('id');
            $table->text('message')->nullable()->after('title');
            $table->timestamp('notified_at')->nullable()->index()->after('message');
            $table->boolean('read')->nullable()->index()->after('notified_at');
            $table->string('type')->nullable()->index()->after('read');
            $table->string('user_id')->nullable()->index()->after('type');
            $table->string('company_id')->nullable()->index()->after('user_id');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('user_id')->nullable()->index()->after('id');
            $table->string('action')->nullable()->index()->after('user_id');
            $table->string('entity')->nullable()->index()->after('action');
            $table->string('entity_id')->nullable()->index()->after('entity');
            $table->timestamp('audited_at')->nullable()->index()->after('entity_id');
            $table->string('company_id')->nullable()->index()->after('audited_at');
        });

        $this->backfillSuppliers();
        $this->backfillCustomers();
        $this->backfillPlanning();
        $this->backfillNotifications();
        $this->backfillAudits();
    }

    public function down(): void
    {
        foreach ([
            'audit_logs' => ['user_id', 'action', 'entity', 'entity_id', 'audited_at', 'company_id'],
            'notifications' => ['title', 'message', 'notified_at', 'read', 'type', 'user_id', 'company_id'],
            'planning_events' => ['title', 'event_at', 'type', 'related_id', 'warehouse_id', 'company_id'],
            'customers' => ['name', 'phone', 'email', 'ice', 'notes', 'active', 'addresses', 'company_id'],
            'suppliers' => [
                'name', 'contact', 'email', 'phone', 'city', 'address', 'ice', 'category',
                'payment_terms', 'note', 'lead_time_days', 'reliability', 'status', 'company_id',
            ],
        ] as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                $blueprint->dropColumn($columns);
            });
        }
    }

    private function backfillSuppliers(): void
    {
        DB::table('suppliers')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $payload = json_decode($row->payload ?? '[]', true) ?: [];
                DB::table('suppliers')->where('id', $row->id)->update([
                    'name' => $payload['name'] ?? null,
                    'contact' => $payload['contact'] ?? null,
                    'email' => $payload['email'] ?? null,
                    'phone' => $payload['phone'] ?? null,
                    'city' => $payload['city'] ?? null,
                    'address' => $payload['address'] ?? null,
                    'ice' => $payload['ice'] ?? null,
                    'category' => $payload['category'] ?? null,
                    'payment_terms' => $payload['paymentTerms'] ?? null,
                    'note' => $payload['note'] ?? null,
                    'lead_time_days' => $payload['leadTimeDays'] ?? null,
                    'reliability' => $payload['reliability'] ?? null,
                    'status' => $payload['status'] ?? null,
                    'company_id' => $payload['companyId'] ?? null,
                ]);
            }
        });
    }

    private function backfillCustomers(): void
    {
        DB::table('customers')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $payload = json_decode($row->payload ?? '[]', true) ?: [];
                DB::table('customers')->where('id', $row->id)->update([
                    'name' => $payload['name'] ?? null,
                    'phone' => $payload['phone'] ?? null,
                    'email' => $payload['email'] ?? null,
                    'ice' => $payload['ice'] ?? null,
                    'notes' => $payload['notes'] ?? null,
                    'active' => array_key_exists('active', $payload) ? (bool) $payload['active'] : null,
                    'addresses' => isset($payload['addresses']) ? json_encode(array_values($payload['addresses'])) : null,
                    'company_id' => $payload['companyId'] ?? null,
                ]);
            }
        });
    }

    private function backfillPlanning(): void
    {
        DB::table('planning_events')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $payload = json_decode($row->payload ?? '[]', true) ?: [];
                $update = [
                    'title' => $payload['title'] ?? null,
                    'type' => $payload['type'] ?? null,
                    'related_id' => $payload['relatedId'] ?? null,
                    'warehouse_id' => $payload['warehouseId'] ?? null,
                    'company_id' => $payload['companyId'] ?? null,
                ];
                if (! empty($payload['date'])) {
                    try {
                        $update['event_at'] = \Carbon\Carbon::parse($payload['date'])->toDateTimeString();
                    } catch (\Throwable) {
                        $update['event_at'] = null;
                    }
                }
                DB::table('planning_events')->where('id', $row->id)->update($update);
            }
        });
    }

    private function backfillNotifications(): void
    {
        DB::table('notifications')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $payload = json_decode($row->payload ?? '[]', true) ?: [];
                $update = [
                    'title' => $payload['title'] ?? null,
                    'message' => $payload['message'] ?? null,
                    'read' => array_key_exists('read', $payload) ? (bool) $payload['read'] : null,
                    'type' => $payload['type'] ?? null,
                    'user_id' => $payload['userId'] ?? null,
                    'company_id' => $payload['companyId'] ?? null,
                ];
                if (! empty($payload['createdAt'])) {
                    try {
                        $update['notified_at'] = \Carbon\Carbon::parse($payload['createdAt'])->toDateTimeString();
                    } catch (\Throwable) {
                        $update['notified_at'] = null;
                    }
                }
                DB::table('notifications')->where('id', $row->id)->update($update);
            }
        });
    }

    private function backfillAudits(): void
    {
        DB::table('audit_logs')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $payload = json_decode($row->payload ?? '[]', true) ?: [];
                $update = [
                    'user_id' => $payload['userId'] ?? null,
                    'action' => $payload['action'] ?? null,
                    'entity' => $payload['entity'] ?? null,
                    'entity_id' => $payload['entityId'] ?? null,
                    'company_id' => $payload['companyId'] ?? null,
                ];
                if (! empty($payload['createdAt'])) {
                    try {
                        $update['audited_at'] = \Carbon\Carbon::parse($payload['createdAt'])->toDateTimeString();
                    } catch (\Throwable) {
                        $update['audited_at'] = null;
                    }
                }
                DB::table('audit_logs')->where('id', $row->id)->update($update);
            }
        });
    }
};
