<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Repositories\Eloquent\PlatformInvoiceRepository;
use App\Repositories\Eloquent\SubscriptionPlanRepository;
use App\Repositories\Eloquent\SubscriptionRepository;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelationalBillingSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);
        Company::query()->create([
            'id' => 'co-bill-rel',
            'name' => 'Bill Rel',
            'legal_name' => 'Bill Rel',
            'ice' => '',
            'city' => 'Rabat',
            'email' => 'billrel@test.local',
            'phone' => '',
            'status' => 'trial',
            'users_count' => 1,
            'warehouses_count' => 1,
        ]);
        $actor = User::query()->create([
            'id' => 'usr-bill-rel',
            'first_name' => 'Bill',
            'last_name' => 'Rel',
            'email' => 'billrel-user@test.local',
            'password' => 'demo',
            'role' => 'TENANT_OWNER',
            'title' => 'Owner',
            'avatar_hue' => 1,
            'warehouse_ids' => [],
            'product_ids' => [],
            'region_ids' => [],
            'company_id' => 'co-bill-rel',
        ]);
        $this->actingAs($actor, 'api');
    }

    public function test_plans_subscriptions_invoices_and_expired_trial_entitlements(): void
    {
        app(SubscriptionPlanRepository::class)->put([
            'id' => 'plan-pro',
            'name' => 'Pro',
            'code' => 'pro',
            'description' => 'Pro',
            'monthlyPrice' => 2490,
            'yearlyPrice' => 24900,
            'maxUsers' => 25,
            'maxWarehouses' => 5,
            'modules' => ['stock', 'logistics'],
            'active' => true,
            'stripePriceMonthly' => 'price_m',
            'stripePriceYearly' => 'price_y',
        ]);
        app(SubscriptionRepository::class)->put([
            'id' => 'sub-rel',
            'companyId' => 'co-bill-rel',
            'planId' => 'plan-pro',
            'status' => 'trialing',
            'interval' => 'monthly',
            'startedAt' => '2026-10-01',
            'currentPeriodEnd' => '2026-10-15',
            'trialEndsAt' => '2026-10-15',
            'seats' => 1,
            'stripeCustomerId' => 'cus_x',
        ]);
        app(PlatformInvoiceRepository::class)->put([
            'id' => 'pinv-rel',
            'companyId' => 'co-bill-rel',
            'subscriptionId' => 'sub-rel',
            'number' => 'BS-2026-099',
            'amount' => 2490,
            'status' => 'open',
            'issuedAt' => '2026-10-01',
            'dueDate' => '2026-10-15',
        ]);

        $this->assertDatabaseHas('subscription_plans', [
            'id' => 'plan-pro',
            'code' => 'pro',
            'stripe_price_monthly' => 'price_m',
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'id' => 'sub-rel',
            'company_id' => 'co-bill-rel',
            'status' => 'trialing',
            'stripe_customer_id' => 'cus_x',
        ]);
        $this->assertDatabaseHas('platform_invoices', [
            'id' => 'pinv-rel',
            'number' => 'BS-2026-099',
            'status' => 'open',
        ]);

        $this->assertSame('sub-rel', app(SubscriptionRepository::class)->forCompany('co-bill-rel')['id']);
        $this->assertCount(1, app(PlatformInvoiceRepository::class)->forCompany('co-bill-rel'));
        $this->assertCount(1, app(SubscriptionRepository::class)->expiredTrials('2026-11-10'));

        $snapshot = app(EntitlementService::class)->snapshot('co-bill-rel');
        $this->assertSame('canceled', $snapshot['status']);
        $this->assertSame([], $snapshot['modules']);

        $this->artisan('billing:expire-trials')->assertSuccessful();
        $this->assertSame('canceled', app(SubscriptionRepository::class)->find('sub-rel')['status']);
        $this->assertSame('churned', Company::query()->find('co-bill-rel')?->status);
    }
}
