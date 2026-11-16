<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Company;
use App\Repositories\Eloquent\PlatformInvoiceRepository;
use App\Repositories\Eloquent\SubscriptionPlanRepository;
use App\Repositories\Eloquent\SubscriptionRepository;
use App\Support\BillingCountries;
use App\Support\PlanPricing;
use App\Support\Tenant;
use App\Support\Uid;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PlatformService
{
    public function __construct(
        private readonly SubscriptionPlanRepository $plans,
        private readonly SubscriptionRepository $subscriptions,
        private readonly PlatformInvoiceRepository $invoices,
        private readonly Tenant $tenant,
    ) {}

    public function companies(): Collection
    {
        $query = Company::query()->orderBy('name');
        if (! $this->tenant->isPlatform()) {
            $query->where('id', $this->tenant->companyId());
        }

        return $query->get()->map(fn (Company $company) => $this->companyArray($company));
    }

    public function plans(): Collection
    {
        return $this->plans->all();
    }

    public function subscriptions(): Collection
    {
        if ($this->tenant->isPlatform()) {
            return $this->subscriptions->forCompanies();
        }
        $subscription = $this->subscriptions->forCompany($this->tenant->companyId());

        return collect($subscription ? [$subscription] : []);
    }

    public function invoices(): Collection
    {
        if ($this->tenant->isPlatform()) {
            return $this->invoices->all();
        }

        return $this->invoices->forCompany($this->tenant->companyId());
    }

    public function createCompany(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $name = trim($input['name'] ?? '');
            if (strlen($name) < 2) {
                throw new DomainException('Le nom de l’entreprise est requis.');
            }
            $plan = $this->plans->find($input['planId'] ?? '');
            if (! $plan || empty($plan['active'])) {
                throw new DomainException('Plan introuvable.');
            }
            $country = BillingCountries::normalize($input['country'] ?? null);
            PlanPricing::require($plan, $country);
            $id = Uid::make('co');
            $company = Company::query()->create([
                'id' => $id,
                'name' => $name,
                'legal_name' => trim($input['legalName'] ?? '') ?: $name,
                'ice' => trim($input['ice'] ?? ''),
                'city' => trim($input['city'] ?? ''),
                'country' => $country,
                'email' => strtolower(trim($input['email'] ?? '')),
                'phone' => trim($input['phone'] ?? ''),
                'status' => 'trial',
                'users_count' => 1,
                'warehouses_count' => 1,
            ]);
            $ends = $this->trialEnd();
            $this->subscriptions->put([
                'id' => Uid::make('sub'),
                'companyId' => $company->id,
                'planId' => $plan['id'],
                'status' => 'trialing',
                'interval' => $input['interval'] ?? 'monthly',
                'startedAt' => now()->toDateString(),
                'currentPeriodEnd' => $ends,
                'trialEndsAt' => $ends,
                'seats' => 1,
            ]);

            return $this->companyArray($company);
        });
    }

    public function updateCompany(string $id, array $input): array
    {
        return DB::transaction(function () use ($id, $input) {
            $this->tenant->assertCompanyAccess($id);
            $company = Company::query()->find($id);
            if (! $company) {
                throw new DomainException('Entreprise introuvable.');
            }
            foreach (['name' => 'name', 'legalName' => 'legal_name', 'ice' => 'ice', 'city' => 'city', 'phone' => 'phone'] as $from => $to) {
                if (array_key_exists($from, $input)) {
                    $company->{$to} = trim((string) $input[$from]);
                }
            }
            if (array_key_exists('email', $input)) {
                $company->email = strtolower(trim((string) $input['email']));
            }
            if (array_key_exists('country', $input)) {
                $country = BillingCountries::normalize($input['country']);
                if ($country !== $company->country && ! $this->tenant->isPlatform()) {
                    throw new DomainException('Le pays de facturation est modifiable uniquement par BravoStock.');
                }
                if ($country !== $company->country) {
                    $current = $this->subscriptions->forCompany($id);
                    $plan = $current ? $this->plans->find($current['planId'] ?? '') : null;
                    if ($plan) {
                        PlanPricing::require($plan, $country);
                    }
                    $company->country = $country;
                }
            }
            if (! empty($input['status'])) {
                $company->status = $input['status'];
                $subscription = $this->subscriptions->forCompany($id);
                if ($subscription) {
                    if ($input['status'] === 'suspended') {
                        $subscription['status'] = 'paused';
                    }
                    if ($input['status'] === 'churned') {
                        $subscription['status'] = 'canceled';
                    }
                    if ($input['status'] === 'active' && in_array($subscription['status'], ['paused', 'canceled'], true)) {
                        $subscription['status'] = 'active';
                    }
                    if ($input['status'] === 'trial') {
                        $subscription['status'] = 'trialing';
                    }
                    $this->subscriptions->put($subscription);
                }
            }
            $company->save();

            return $this->companyArray($company);
        });
    }

    public function createPlan(array $input): array
    {
        $name = trim($input['name'] ?? '');
        if (strlen($name) < 2) {
            throw new DomainException('Le nom du plan est requis.');
        }
        $code = strtolower(preg_replace('/[^a-z0-9]+/', '-', trim($input['code'] ?? '') ?: $name) ?? '');
        if ($this->plans->all()->contains(fn (array $plan) => $plan['code'] === $code)) {
            throw new DomainException('Ce code de plan existe déjà.');
        }
        $prices = $this->pricesFromInput($input);

        return $this->plans->put([
            'id' => Uid::make('plan'),
            'name' => $name,
            'code' => $code,
            'description' => trim($input['description'] ?? ''),
            'prices' => $prices,
            ...$this->referencePrices($prices),
            'maxUsers' => max(0, (int) round($input['maxUsers'] ?? 0)),
            'maxWarehouses' => max(0, (int) round($input['maxWarehouses'] ?? 0)),
            'modules' => $input['modules'] ?? [],
            'active' => (bool) ($input['active'] ?? true),
            'stripePriceMonthly' => trim((string) ($input['stripePriceMonthly'] ?? '')),
            'stripePriceYearly' => trim((string) ($input['stripePriceYearly'] ?? '')),
        ]);
    }

    public function updatePlan(string $id, array $input): array
    {
        $plan = $this->plans->findOrFail($id, 'Plan introuvable.');
        if (array_key_exists('name', $input)) {
            $plan['name'] = trim($input['name']);
        }
        if (array_key_exists('description', $input)) {
            $plan['description'] = trim($input['description']);
        }
        foreach (['maxUsers', 'maxWarehouses'] as $key) {
            if (array_key_exists($key, $input)) {
                $plan[$key] = max(0, (int) round($input[$key]));
            }
        }
        if (array_key_exists('prices', $input)) {
            $plan['prices'] = $this->pricesFromInput($input);
        } elseif (array_key_exists('monthlyPrice', $input) || array_key_exists('yearlyPrice', $input)) {
            // Ancien format (prix uniques) : ils pilotent le tarif Maroc.
            $prices = $plan['prices'] ?? [];
            $ma = PlanPricing::for($plan, BillingCountries::DEFAULT) ?? ['monthly' => 0, 'yearly' => 0];
            $prices[BillingCountries::DEFAULT] = [
                ...($prices[BillingCountries::DEFAULT] ?? []),
                'monthly' => max(0, (int) round($input['monthlyPrice'] ?? $ma['monthly'])),
                'yearly' => max(0, (int) round($input['yearlyPrice'] ?? $ma['yearly'])),
            ];
            $plan['prices'] = $prices;
        }
        if (isset($plan['prices'])) {
            $plan = [...$plan, ...$this->referencePrices($plan['prices'])];
        }
        foreach (['stripePriceMonthly', 'stripePriceYearly'] as $key) {
            if (array_key_exists($key, $input)) {
                $plan[$key] = trim((string) $input[$key]);
            }
        }
        if (array_key_exists('modules', $input)) {
            $plan['modules'] = $input['modules'];
        }
        if (array_key_exists('active', $input)) {
            $plan['active'] = (bool) $input['active'];
        }

        return $this->plans->put($plan);
    }

    public function changeSubscription(string $companyId, array $input): array
    {
        return DB::transaction(function () use ($companyId, $input) {
            $this->tenant->assertCompanyAccess($companyId);
            $subscription = $this->subscriptions->forCompany($companyId);
            if (! $subscription) {
                throw new DomainException('Abonnement introuvable.');
            }
            $company = Company::query()->find($companyId);
            if (! empty($input['planId'])) {
                $plan = $this->plans->find($input['planId']);
                if (! $plan) {
                    throw new DomainException('Plan introuvable.');
                }
                PlanPricing::require($plan, $company?->country ?? BillingCountries::DEFAULT);
                $subscription['planId'] = $plan['id'];
            }
            if (! empty($input['interval'])) {
                $subscription['interval'] = $input['interval'];
                $subscription['currentPeriodEnd'] = $this->periodEnd($input['interval']);
            }
            if (! empty($input['status'])) {
                $subscription['status'] = $input['status'];
                if ($company) {
                    if ($input['status'] === 'paused') {
                        $company->status = 'suspended';
                    }
                    if ($input['status'] === 'canceled') {
                        $company->status = 'churned';
                    }
                    if ($input['status'] === 'active') {
                        $company->status = 'active';
                    }
                    if ($input['status'] === 'trialing') {
                        $company->status = 'trial';
                    }
                    $company->save();
                }
            }
            $this->subscriptions->put($subscription);
            if (! empty($input['planId']) || ! empty($input['interval'])) {
                $plan = $this->plans->find($subscription['planId']);
                if ($plan && ($subscription['status'] ?? '') === 'active') {
                    $country = $company?->country ?? BillingCountries::DEFAULT;
                    $this->invoices->put([
                        'id' => Uid::make('pinv'),
                        'companyId' => $companyId,
                        'subscriptionId' => $subscription['id'],
                        'number' => $this->nextInvoiceNumber(),
                        'amount' => PlanPricing::amount($plan, $country, (string) ($subscription['interval'] ?? 'monthly')),
                        'currency' => BillingCountries::currency($country),
                        'status' => 'open',
                        'issuedAt' => now()->toDateString(),
                        'dueDate' => $this->addDays(now()->toDateString(), 14),
                    ]);
                }
            }

            return $subscription;
        });
    }

    /**
     * Paiement facture plateforme : stub immédiat, ou redirection Checkout si BILLING_PROVIDER=stripe.
     *
     * @return array{
     *   invoice?: array,
     *   payment?: array{provider: string, paymentRef: string, status: string},
     *   url?: string,
     *   sessionId?: string,
     *   provider?: string
     * }
     */
    public function payInvoice(string $id, array $input = []): array
    {
        $provider = trim((string) ($input['provider'] ?? config('services.billing.provider', 'stub')));
        if ($provider === '') {
            $provider = 'stub';
        }
        if ($provider === 'stripe') {
            return app(StripeBillingService::class)->createCheckoutSession(
                null,
                null,
                null,
                $id,
            );
        }

        return DB::transaction(function () use ($id, $input, $provider) {
            $invoice = $this->invoices->findOrFail($id, 'Facture introuvable.');
            $this->tenant->assertCompanyAccess($invoice['companyId'] ?? null);
            if (($invoice['status'] ?? '') === 'paid') {
                throw new DomainException('Facture déjà payée.');
            }
            if (! in_array($invoice['status'] ?? '', ['open', 'overdue'], true)) {
                throw new DomainException('Cette facture n’est pas payable.');
            }
            $paymentRef = trim((string) ($input['paymentRef'] ?? ''))
                ?: strtoupper($provider).'-'.strtoupper(substr(Uid::make('pay'), -10));
            $invoice['status'] = 'paid';
            $invoice['paidAt'] = now()->toDateString();
            $invoice['paymentProvider'] = $provider;
            $invoice['paymentRef'] = $paymentRef;
            $this->invoices->put($invoice);
            $overdue = $this->invoices->hasOpenForCompany((string) $invoice['companyId']);
            $subscription = $this->subscriptions->forCompany((string) $invoice['companyId']);
            $company = Company::query()->find($invoice['companyId']);
            if (! $overdue && ($subscription['status'] ?? '') === 'past_due') {
                $subscription['status'] = 'active';
                $this->subscriptions->put($subscription);
                if ($company && $company->status !== 'suspended') {
                    $company->status = 'active';
                    $company->save();
                }
            }

            return [
                'invoice' => $invoice,
                'payment' => [
                    'provider' => $provider,
                    'paymentRef' => $paymentRef,
                    'status' => 'succeeded',
                ],
            ];
        });
    }

    private function companyArray(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'legalName' => $company->legal_name,
            'ice' => $company->ice,
            'city' => $company->city,
            'country' => $company->country ?: BillingCountries::DEFAULT,
            'currency' => BillingCountries::currency($company->country),
            'email' => $company->email,
            'phone' => $company->phone,
            'status' => $company->status,
            'createdAt' => optional($company->created_at)->toDateString() ?? now()->toDateString(),
            'usersCount' => $company->users_count,
            'warehousesCount' => $company->warehouses_count,
        ];
    }

    /**
     * Grille de prix saisie. Ancien format accepté : monthlyPrice/yearlyPrice → tarif Maroc.
     *
     * @return array<string, array<string, mixed>>
     */
    private function pricesFromInput(array $input): array
    {
        $prices = PlanPricing::sanitize($input['prices'] ?? null);
        if (! $prices && array_key_exists('monthlyPrice', $input)) {
            $prices = PlanPricing::sanitize([BillingCountries::DEFAULT => [
                'monthly' => $input['monthlyPrice'],
                'yearly' => $input['yearlyPrice'] ?? null,
                'stripePriceMonthly' => $input['stripePriceMonthly'] ?? '',
                'stripePriceYearly' => $input['stripePriceYearly'] ?? '',
            ]]);
        }
        if (! $prices) {
            throw new DomainException('Renseignez le tarif d’au moins un pays.');
        }

        return $prices;
    }

    /**
     * monthlyPrice / yearlyPrice restent alimentés (tri, compatibilité) avec le tarif Maroc,
     * ou 0 quand le plan n'est pas vendu au Maroc.
     *
     * @return array{monthlyPrice: int, yearlyPrice: int}
     */
    private function referencePrices(array $prices): array
    {
        $ma = $prices[BillingCountries::DEFAULT] ?? null;

        return [
            'monthlyPrice' => (int) ($ma['monthly'] ?? 0),
            'yearlyPrice' => (int) ($ma['yearly'] ?? 0),
        ];
    }

    private function periodEnd(string $interval, ?string $from = null): string
    {
        $from ??= now()->toDateString();
        $date = new \DateTimeImmutable($from.'T12:00:00');
        $date = $interval === 'yearly' ? $date->modify('+1 year') : $date->modify('+1 month');

        return $date->format('Y-m-d');
    }

    private function trialEnd(?string $from = null): string
    {
        return $this->addDays($from ?? now()->toDateString(), 14);
    }

    private function addDays(string $from, int $days): string
    {
        return (new \DateTimeImmutable($from.'T12:00:00'))->modify("+$days days")->format('Y-m-d');
    }

    private function nextInvoiceNumber(): string
    {
        $count = $this->invoices->all()->count() + 1;

        return 'BS-2026-'.str_pad((string) $count, 3, '0', STR_PAD_LEFT);
    }
}
