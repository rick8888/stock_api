<?php

namespace Tests\Feature;

use App\Billing\FakeStripeGateway;
use App\Billing\StripeGateway;
use App\Models\Company;
use App\Models\User;
use App\Repositories\Eloquent\PlatformInvoiceRepository;
use App\Repositories\Eloquent\SubscriptionPlanRepository;
use App\Repositories\Eloquent\SubscriptionRepository;
use App\Services\StripeBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StripeBillingTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);
        config([
            'services.billing.provider' => 'stripe',
            'services.billing.stripe.secret' => 'sk_test_fake',
            'services.billing.stripe.webhook_secret' => 'whsec_fake',
            'services.billing.stripe.success_url' => 'http://front.test/account?tab=billing',
            'services.billing.stripe.cancel_url' => 'http://front.test/account?tab=billing',
            'services.billing.stripe.portal_return_url' => 'http://front.test/account?tab=billing',
            'app.frontend_url' => 'http://front.test',
        ]);
        $this->gateway = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $this->gateway);

        Company::query()->create([
            'id' => 'co-bill',
            'name' => 'Bill Co',
            'legal_name' => 'Bill Co SARL',
            'ice' => '',
            'city' => 'Casablanca',
            'email' => 'billing@test.local',
            'phone' => '',
            'status' => 'trial',
            'users_count' => 1,
            'warehouses_count' => 1,
        ]);
        $actor = User::query()->create([
            'id' => 'usr-bill',
            'first_name' => 'Bill',
            'last_name' => 'Owner',
            'email' => 'bill@test.local',
            'password' => 'demo',
            'role' => 'TENANT_OWNER',
            'title' => 'Owner',
            'avatar_hue' => 1,
            'warehouse_ids' => [],
            'product_ids' => [],
            'region_ids' => [],
            'company_id' => 'co-bill',
        ]);
        $this->actingAs($actor, 'api');

        app(SubscriptionPlanRepository::class)->put([
            'id' => 'plan-pro',
            'name' => 'Pro',
            'code' => 'pro',
            'monthlyPrice' => 2490,
            'yearlyPrice' => 24900,
            'maxUsers' => 20,
            'maxWarehouses' => 5,
            'modules' => ['stock'],
            'active' => true,
            'stripePriceMonthly' => 'price_monthly_pro',
            'stripePriceYearly' => 'price_yearly_pro',
        ]);
        app(SubscriptionRepository::class)->put([
            'id' => 'sub-bill',
            'companyId' => 'co-bill',
            'planId' => 'plan-pro',
            'status' => 'trialing',
            'interval' => 'monthly',
            'startedAt' => now()->toDateString(),
            'currentPeriodEnd' => now()->addDays(14)->toDateString(),
            'trialEndsAt' => now()->addDays(14)->toDateString(),
            'seats' => 1,
        ]);
    }

    public function test_checkout_and_portal_return_urls(): void
    {
        $billing = app(StripeBillingService::class);
        $checkout = $billing->createCheckoutSession('co-bill', 'plan-pro', 'monthly');
        $this->assertSame('stripe', $checkout['provider']);
        $this->assertStringContainsString('checkout.stripe.test', $checkout['url']);
        $this->assertNotEmpty($this->gateway->calls);

        $portal = $billing->createPortalSession('co-bill');
        $this->assertStringContainsString('billing.stripe.test', $portal['url']);

        $sub = app(SubscriptionRepository::class)->find('sub-bill');
        $this->assertNotEmpty($sub['stripeCustomerId'] ?? null);
    }

    public function test_webhook_activates_subscription_after_checkout(): void
    {
        $billing = app(StripeBillingService::class);
        $billing->createCheckoutSession('co-bill', 'plan-pro', 'monthly');

        $result = $billing->handleWebhook(json_encode([
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_1',
                    'mode' => 'subscription',
                    'customer' => 'cus_from_webhook',
                    'subscription' => 'sub_stripe_1',
                    'client_reference_id' => 'co-bill',
                    'metadata' => [
                        'companyId' => 'co-bill',
                        'planId' => 'plan-pro',
                        'interval' => 'monthly',
                    ],
                ],
            ],
        ]) ?: '', 'sig_ok');

        $this->assertTrue($result['handled']);
        $sub = app(SubscriptionRepository::class)->find('sub-bill');
        $this->assertSame('active', $sub['status']);
        $this->assertSame('sub_stripe_1', $sub['stripeSubscriptionId']);
        $this->assertSame('active', Company::query()->find('co-bill')?->status);
    }

    public function test_pay_invoice_via_stripe_checkout(): void
    {
        app(PlatformInvoiceRepository::class)->put([
            'id' => 'pinv-1',
            'companyId' => 'co-bill',
            'subscriptionId' => 'sub-bill',
            'number' => 'BS-2026-001',
            'amount' => 2490,
            'status' => 'open',
            'issuedAt' => now()->toDateString(),
            'dueDate' => now()->addDays(14)->toDateString(),
        ]);

        $checkout = app(StripeBillingService::class)->createCheckoutSession(null, null, null, 'pinv-1');
        $this->assertSame('stripe', $checkout['provider']);

        app(StripeBillingService::class)->handleWebhook(json_encode([
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_pay_1',
                    'mode' => 'payment',
                    'customer' => 'cus_x',
                    'payment_intent' => 'pi_1',
                    'metadata' => [
                        'companyId' => 'co-bill',
                        'platformInvoiceId' => 'pinv-1',
                    ],
                ],
            ],
        ]) ?: '', 'sig_ok');

        $invoice = app(PlatformInvoiceRepository::class)->find('pinv-1');
        $this->assertSame('paid', $invoice['status']);
        $this->assertSame('stripe', $invoice['paymentProvider']);
    }
}
