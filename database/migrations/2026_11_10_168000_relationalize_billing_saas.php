<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
            $table->string('code')->nullable()->index()->after('name');
            $table->text('description')->nullable()->after('code');
            $table->unsignedInteger('monthly_price')->nullable()->after('description');
            $table->unsignedInteger('yearly_price')->nullable()->after('monthly_price');
            $table->unsignedInteger('max_users')->nullable()->after('yearly_price');
            $table->unsignedInteger('max_warehouses')->nullable()->after('max_users');
            $table->json('modules')->nullable()->after('max_warehouses');
            $table->boolean('active')->nullable()->index()->after('modules');
            $table->string('stripe_price_monthly')->nullable()->after('active');
            $table->string('stripe_price_yearly')->nullable()->after('stripe_price_monthly');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('company_id')->nullable()->index()->after('id');
            $table->string('plan_id')->nullable()->index()->after('company_id');
            $table->string('status')->nullable()->index()->after('plan_id');
            $table->string('interval')->nullable()->after('status');
            $table->date('started_at')->nullable()->after('interval');
            $table->date('current_period_end')->nullable()->index()->after('started_at');
            $table->date('trial_ends_at')->nullable()->index()->after('current_period_end');
            $table->unsignedInteger('seats')->nullable()->after('trial_ends_at');
            $table->string('stripe_customer_id')->nullable()->index()->after('seats');
            $table->string('stripe_subscription_id')->nullable()->index()->after('stripe_customer_id');
        });

        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->string('company_id')->nullable()->index()->after('id');
            $table->string('subscription_id')->nullable()->index()->after('company_id');
            $table->string('number')->nullable()->index()->after('subscription_id');
            $table->decimal('amount', 14, 2)->nullable()->after('number');
            $table->string('status')->nullable()->index()->after('amount');
            $table->date('issued_at')->nullable()->index()->after('status');
            $table->date('due_date')->nullable()->index()->after('issued_at');
            $table->date('paid_at')->nullable()->after('due_date');
            $table->string('payment_provider')->nullable()->after('paid_at');
            $table->string('payment_ref')->nullable()->after('payment_provider');
            $table->string('stripe_invoice_id')->nullable()->index()->after('payment_ref');
        });

        $this->backfillPlans();
        $this->backfillSubscriptions();
        $this->backfillInvoices();
    }

    public function down(): void
    {
        Schema::table('platform_invoices', function (Blueprint $table) {
            $table->dropColumn([
                'company_id', 'subscription_id', 'number', 'amount', 'status', 'issued_at',
                'due_date', 'paid_at', 'payment_provider', 'payment_ref', 'stripe_invoice_id',
            ]);
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn([
                'company_id', 'plan_id', 'status', 'interval', 'started_at', 'current_period_end',
                'trial_ends_at', 'seats', 'stripe_customer_id', 'stripe_subscription_id',
            ]);
        });
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn([
                'name', 'code', 'description', 'monthly_price', 'yearly_price', 'max_users',
                'max_warehouses', 'modules', 'active', 'stripe_price_monthly', 'stripe_price_yearly',
            ]);
        });
    }

    private function backfillPlans(): void
    {
        DB::table('subscription_plans')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $payload = json_decode($row->payload ?? '[]', true) ?: [];
                DB::table('subscription_plans')->where('id', $row->id)->update([
                    'name' => $payload['name'] ?? null,
                    'code' => $payload['code'] ?? null,
                    'description' => $payload['description'] ?? null,
                    'monthly_price' => $payload['monthlyPrice'] ?? null,
                    'yearly_price' => $payload['yearlyPrice'] ?? null,
                    'max_users' => $payload['maxUsers'] ?? null,
                    'max_warehouses' => $payload['maxWarehouses'] ?? null,
                    'modules' => isset($payload['modules']) ? json_encode(array_values($payload['modules'])) : null,
                    'active' => array_key_exists('active', $payload) ? (bool) $payload['active'] : null,
                    'stripe_price_monthly' => $payload['stripePriceMonthly'] ?? null,
                    'stripe_price_yearly' => $payload['stripePriceYearly'] ?? null,
                ]);
            }
        });
    }

    private function backfillSubscriptions(): void
    {
        DB::table('subscriptions')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $payload = json_decode($row->payload ?? '[]', true) ?: [];
                $update = [
                    'company_id' => $payload['companyId'] ?? null,
                    'plan_id' => $payload['planId'] ?? null,
                    'status' => $payload['status'] ?? null,
                    'interval' => $payload['interval'] ?? null,
                    'seats' => $payload['seats'] ?? null,
                    'stripe_customer_id' => $payload['stripeCustomerId'] ?? null,
                    'stripe_subscription_id' => $payload['stripeSubscriptionId'] ?? null,
                ];
                foreach ([
                    'startedAt' => 'started_at',
                    'currentPeriodEnd' => 'current_period_end',
                    'trialEndsAt' => 'trial_ends_at',
                ] as $from => $to) {
                    if (! empty($payload[$from])) {
                        try {
                            $update[$to] = \Carbon\Carbon::parse($payload[$from])->toDateString();
                        } catch (\Throwable) {
                            $update[$to] = null;
                        }
                    }
                }
                DB::table('subscriptions')->where('id', $row->id)->update($update);
            }
        });
    }

    private function backfillInvoices(): void
    {
        DB::table('platform_invoices')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $payload = json_decode($row->payload ?? '[]', true) ?: [];
                $update = [
                    'company_id' => $payload['companyId'] ?? null,
                    'subscription_id' => $payload['subscriptionId'] ?? null,
                    'number' => $payload['number'] ?? null,
                    'amount' => $payload['amount'] ?? null,
                    'status' => $payload['status'] ?? null,
                    'payment_provider' => $payload['paymentProvider'] ?? null,
                    'payment_ref' => $payload['paymentRef'] ?? null,
                    'stripe_invoice_id' => $payload['stripeInvoiceId'] ?? null,
                ];
                foreach ([
                    'issuedAt' => 'issued_at',
                    'dueDate' => 'due_date',
                    'paidAt' => 'paid_at',
                ] as $from => $to) {
                    if (! empty($payload[$from])) {
                        try {
                            $update[$to] = \Carbon\Carbon::parse($payload[$from])->toDateString();
                        } catch (\Throwable) {
                            $update[$to] = null;
                        }
                    }
                }
                DB::table('platform_invoices')->where('id', $row->id)->update($update);
            }
        });
    }
};
