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

class CountryPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);

        $admin = User::query()->create([
            'id' => 'usr-super-pricing',
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'email' => 'super-pricing@test.local',
            'password' => 'demo',
            'role' => 'SUPER_ADMIN',
            'title' => 'Plateforme',
            'avatar_hue' => 1,
            'warehouse_ids' => [],
            'product_ids' => [],
            'region_ids' => [],
            'company_id' => null,
        ]);
        $this->actingAs($admin, 'api');

        app(SubscriptionPlanRepository::class)->put([
            'id' => 'plan-pro',
            'name' => 'Pro',
            'code' => 'pro',
            'description' => '',
            'monthlyPrice' => 2490,
            'yearlyPrice' => 24900,
            'prices' => [
                'MA' => ['monthly' => 2490, 'yearly' => 24900],
                'CI' => ['monthly' => 149000, 'yearly' => 1490000, 'stripePriceMonthly' => 'price_pro_ci_m'],
                'SN' => ['monthly' => 149000, 'yearly' => 1490000],
                'CD' => ['monthly' => 249, 'yearly' => 2490],
            ],
            'maxUsers' => 25,
            'maxWarehouses' => 5,
            'modules' => ['stock'],
            'active' => true,
        ]);
    }

    private function createCompany(string $country, string $planId = 'plan-pro')
    {
        return $this->postJson('/api/platform/companies', [
            'name' => "Client {$country}",
            'legalName' => "Client {$country} SA",
            'city' => 'Ville',
            'email' => strtolower($country).'@client.test',
            'phone' => '',
            'country' => $country,
            'planId' => $planId,
            'interval' => 'monthly',
        ]);
    }

    public function test_company_keeps_its_country_and_currency(): void
    {
        $response = $this->createCompany('CI')->assertSuccessful();

        $this->assertSame('CI', $response->json('country'));
        $this->assertSame('XOF', $response->json('currency'));
        $this->assertSame('CI', Company::query()->find($response->json('id'))?->country);
    }

    public function test_company_defaults_to_morocco(): void
    {
        $response = $this->postJson('/api/platform/companies', [
            'name' => 'Sans pays',
            'city' => 'Casablanca',
            'email' => 'sans-pays@client.test',
            'planId' => 'plan-pro',
        ])->assertSuccessful();

        $this->assertSame('MA', $response->json('country'));
        $this->assertSame('MAD', $response->json('currency'));
    }

    public function test_plan_without_price_for_country_is_refused(): void
    {
        $this->createCompany('CG')->assertStatus(422);
        $this->assertSame(0, Company::query()->count());
    }

    public function test_unknown_country_is_refused(): void
    {
        $this->createCompany('FR')->assertStatus(422);
    }

    public function test_invoice_uses_country_price_and_currency(): void
    {
        $id = $this->createCompany('CD')->json('id');
        $this->patchJson("/api/platform/companies/{$id}/subscription", ['status' => 'active'])->assertSuccessful();
        $this->patchJson("/api/platform/companies/{$id}/subscription", ['interval' => 'yearly'])->assertSuccessful();

        $invoice = app(PlatformInvoiceRepository::class)->forCompany($id)->first();
        $this->assertSame(2490.0, (float) $invoice['amount']);
        $this->assertSame('USD', $invoice['currency']);
    }

    public function test_plan_stores_a_price_grid_and_drops_empty_countries(): void
    {
        $response = $this->postJson('/api/platform/plans', [
            'name' => 'Afrique',
            'code' => 'afrique',
            'description' => '',
            'prices' => [
                'SN' => ['monthly' => 59000, 'yearly' => 590000],
                'CG' => ['monthly' => 59000],
                'CD' => ['monthly' => ''],
                'FR' => ['monthly' => 10],
            ],
            'maxUsers' => 5,
            'maxWarehouses' => 1,
            'modules' => ['stock'],
            'active' => true,
        ])->assertSuccessful();

        $prices = $response->json('prices');
        $this->assertSame(['SN', 'CG'], array_keys($prices));
        $this->assertSame(590000, $prices['CG']['yearly']);
        $this->assertSame(0, $response->json('monthlyPrice'));
    }

    public function test_plan_requires_at_least_one_price(): void
    {
        $this->postJson('/api/platform/plans', [
            'name' => 'Vide',
            'prices' => ['MA' => ['monthly' => '']],
        ])->assertStatus(422);
    }

    public function test_legacy_single_price_update_drives_the_morocco_price(): void
    {
        $response = $this->patchJson('/api/platform/plans/plan-pro', ['monthlyPrice' => 2990])->assertSuccessful();

        $this->assertSame(2990, $response->json('prices.MA.monthly'));
        $this->assertSame(149000, $response->json('prices.CI.monthly'));
        $this->assertSame(2990, $response->json('monthlyPrice'));
    }

    public function test_country_change_needs_a_price_for_the_current_plan(): void
    {
        $id = $this->createCompany('MA')->json('id');

        $this->patchJson("/api/platform/companies/{$id}", ['country' => 'CG'])->assertStatus(422);
        $this->patchJson("/api/platform/companies/{$id}", ['country' => 'SN'])
            ->assertSuccessful()
            ->assertJsonPath('currency', 'XOF');
    }

    public function test_stripe_uses_invoice_currency_without_cents_for_cfa(): void
    {
        config([
            'services.billing.provider' => 'stripe',
            'services.billing.stripe.secret' => 'sk_test_fake',
            'services.billing.stripe.success_url' => 'http://front.test/account',
            'services.billing.stripe.cancel_url' => 'http://front.test/account',
            'app.frontend_url' => 'http://front.test',
        ]);
        $gateway = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $gateway);

        $id = $this->createCompany('CI')->json('id');
        $subscription = app(SubscriptionRepository::class)->forCompany($id);
        app(PlatformInvoiceRepository::class)->put([
            'id' => 'pinv-ci',
            'companyId' => $id,
            'subscriptionId' => $subscription['id'],
            'number' => 'BS-2026-900',
            'amount' => 149000,
            'currency' => 'XOF',
            'status' => 'open',
            'issuedAt' => now()->toDateString(),
            'dueDate' => now()->addDays(14)->toDateString(),
        ]);

        $billing = app(StripeBillingService::class);
        $billing->createCheckoutSession($id, null, null, 'pinv-ci');
        $session = collect($gateway->calls)->firstWhere('method', 'createCheckoutSession');
        $priceData = $session['params']['line_items'][0]['price_data'];
        $this->assertSame('xof', $priceData['currency']);
        $this->assertSame(149000, $priceData['unit_amount']);

        // Abonnement : le Price Stripe du pays est utilisé.
        $billing->createCheckoutSession($id, 'plan-pro', 'monthly');
        $subscriptionSession = collect($gateway->calls)->where('method', 'createCheckoutSession')->last();
        $this->assertSame('price_pro_ci_m', $subscriptionSession['params']['line_items'][0]['price']);
    }

    public function test_stripe_subscription_refused_without_country_price_id(): void
    {
        config([
            'services.billing.provider' => 'stripe',
            'services.billing.stripe.secret' => 'sk_test_fake',
            'app.frontend_url' => 'http://front.test',
        ]);
        $this->app->instance(StripeGateway::class, new FakeStripeGateway);

        $id = $this->createCompany('SN')->json('id');

        $this->expectException(\App\Exceptions\DomainException::class);
        app(StripeBillingService::class)->createCheckoutSession($id, 'plan-pro', 'monthly');
    }
}
