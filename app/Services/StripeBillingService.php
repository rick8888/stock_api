<?php

namespace App\Services;

use App\Billing\StripeGateway;
use App\Exceptions\DomainException;
use App\Models\Company;
use App\Repositories\Eloquent\PlatformInvoiceRepository;
use App\Repositories\Eloquent\SubscriptionPlanRepository;
use App\Repositories\Eloquent\SubscriptionRepository;
use App\Support\Tenant;
use App\Support\Uid;
use Illuminate\Support\Facades\DB;

class StripeBillingService
{
    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly SubscriptionRepository $subscriptions,
        private readonly SubscriptionPlanRepository $plans,
        private readonly PlatformInvoiceRepository $invoices,
        private readonly Tenant $tenant,
    ) {}

    /**
     * @return array{url: string, sessionId: string, provider: string}
     */
    public function createCheckoutSession(?string $companyId = null, ?string $planId = null, ?string $interval = null, ?string $invoiceId = null): array
    {
        $this->assertStripeEnabled();
        $companyId = $this->resolveCompanyId($companyId);
        $this->tenant->assertCompanyAccess($companyId);
        $company = Company::query()->find($companyId);
        if (! $company) {
            throw new DomainException('Entreprise introuvable.');
        }

        $subscription = $this->subscriptionFor($companyId);
        if (! $subscription) {
            throw new DomainException('Abonnement introuvable.');
        }

        $customerId = $this->ensureCustomer($company, $subscription);

        if ($invoiceId) {
            return $this->checkoutForInvoice($company, $subscription, $customerId, $invoiceId);
        }

        $planId = $planId ?: ($subscription['planId'] ?? '');
        $interval = $interval ?: ($subscription['interval'] ?? 'monthly');
        $plan = $this->plans->find($planId);
        if (! $plan || empty($plan['active'])) {
            throw new DomainException('Plan introuvable.');
        }

        $priceId = $this->priceIdFor($plan, $interval);
        $success = $this->checkoutUrl(config('services.billing.stripe.success_url'));
        $cancel = $this->returnUrl(config('services.billing.stripe.cancel_url'));

        $session = $this->stripe->createCheckoutSession([
            'mode' => 'subscription',
            'customer' => $customerId,
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'success_url' => $success,
            'cancel_url' => $cancel,
            'client_reference_id' => $companyId,
            'metadata' => [
                'companyId' => $companyId,
                'subscriptionId' => $subscription['id'],
                'planId' => $plan['id'],
                'interval' => $interval,
            ],
            'subscription_data' => [
                'metadata' => [
                    'companyId' => $companyId,
                    'subscriptionId' => $subscription['id'],
                    'planId' => $plan['id'],
                    'interval' => $interval,
                ],
            ],
        ]);

        $subscription['stripeCustomerId'] = $customerId;
        $subscription['pendingPlanId'] = $plan['id'];
        $subscription['pendingInterval'] = $interval;
        $this->subscriptions->put($subscription);

        return [
            'url' => $session['url'],
            'sessionId' => $session['id'],
            'provider' => 'stripe',
        ];
    }

    /**
     * @return array{url: string, provider: string}
     */
    public function createPortalSession(?string $companyId = null): array
    {
        $this->assertStripeEnabled();
        $companyId = $this->resolveCompanyId($companyId);
        $this->tenant->assertCompanyAccess($companyId);
        $company = Company::query()->find($companyId);
        if (! $company) {
            throw new DomainException('Entreprise introuvable.');
        }
        $subscription = $this->subscriptionFor($companyId);
        if (! $subscription) {
            throw new DomainException('Abonnement introuvable.');
        }
        $customerId = $this->ensureCustomer($company, $subscription);
        $session = $this->stripe->createBillingPortalSession([
            'customer' => $customerId,
            'return_url' => $this->returnUrl(config('services.billing.stripe.portal_return_url')),
        ]);

        return [
            'url' => $session['url'],
            'provider' => 'stripe',
        ];
    }

    public function handleWebhook(string $payload, string $signature): array
    {
        $secret = (string) config('services.billing.stripe.webhook_secret', '');
        if ($secret === '') {
            throw new DomainException('STRIPE_WEBHOOK_SECRET manquant.', 500);
        }
        $event = $this->stripe->constructEvent($payload, $signature, $secret);
        $type = $event['type'];
        $object = $event['data']['object'];

        return DB::transaction(function () use ($type, $object) {
            return match ($type) {
                'checkout.session.completed' => $this->onCheckoutCompleted($object),
                'customer.subscription.updated', 'customer.subscription.created' => $this->onSubscriptionUpdated($object),
                'customer.subscription.deleted' => $this->onSubscriptionDeleted($object),
                'invoice.paid' => $this->onInvoicePaid($object),
                'invoice.payment_failed' => $this->onInvoicePaymentFailed($object),
                default => ['handled' => false, 'type' => $type],
            };
        });
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array{handled: bool, type: string}
     */
    private function onCheckoutCompleted(array $object): array
    {
        $companyId = (string) ($object['metadata']['companyId'] ?? $object['client_reference_id'] ?? '');
        $mode = (string) ($object['mode'] ?? '');
        if ($companyId === '') {
            return ['handled' => false, 'type' => 'checkout.session.completed'];
        }
        $subscription = $this->subscriptionFor($companyId);
        if (! $subscription) {
            return ['handled' => false, 'type' => 'checkout.session.completed'];
        }

        if (! empty($object['customer'])) {
            $subscription['stripeCustomerId'] = (string) $object['customer'];
        }
        if ($mode === 'subscription' && ! empty($object['subscription'])) {
            $subscription['stripeSubscriptionId'] = (string) $object['subscription'];
            if (! empty($object['metadata']['planId'])) {
                $subscription['planId'] = (string) $object['metadata']['planId'];
            } elseif (! empty($subscription['pendingPlanId'])) {
                $subscription['planId'] = $subscription['pendingPlanId'];
            }
            if (! empty($object['metadata']['interval'])) {
                $subscription['interval'] = (string) $object['metadata']['interval'];
            } elseif (! empty($subscription['pendingInterval'])) {
                $subscription['interval'] = $subscription['pendingInterval'];
            }
            $subscription['status'] = 'active';
            unset($subscription['pendingPlanId'], $subscription['pendingInterval']);
            $this->subscriptions->put($subscription);
            $this->syncCompanyStatus($companyId, 'active');
        }

        if ($mode === 'payment' && ! empty($object['metadata']['platformInvoiceId'])) {
            $this->markPlatformInvoicePaid(
                (string) $object['metadata']['platformInvoiceId'],
                (string) ($object['payment_intent'] ?? $object['id'] ?? 'stripe'),
            );
        }

        return ['handled' => true, 'type' => 'checkout.session.completed'];
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array{handled: bool, type: string}
     */
    private function onSubscriptionUpdated(array $object): array
    {
        $subscription = $this->findByStripeSubscription((string) ($object['id'] ?? ''));
        if (! $subscription && ! empty($object['metadata']['companyId'])) {
            $subscription = $this->subscriptionFor((string) $object['metadata']['companyId']);
        }
        if (! $subscription) {
            return ['handled' => false, 'type' => 'customer.subscription.updated'];
        }

        $subscription['stripeSubscriptionId'] = (string) ($object['id'] ?? $subscription['stripeSubscriptionId'] ?? '');
        if (! empty($object['customer'])) {
            $subscription['stripeCustomerId'] = (string) $object['customer'];
        }
        if (! empty($object['metadata']['planId'])) {
            $subscription['planId'] = (string) $object['metadata']['planId'];
        }
        if (! empty($object['metadata']['interval'])) {
            $subscription['interval'] = (string) $object['metadata']['interval'];
        }
        if (! empty($object['current_period_end'])) {
            $subscription['currentPeriodEnd'] = date('Y-m-d', (int) $object['current_period_end']);
        }

        $status = $this->mapStripeStatus((string) ($object['status'] ?? ''));
        if ($status) {
            $subscription['status'] = $status;
            $this->syncCompanyStatus($subscription['companyId'], $this->companyStatusFor($status));
        }
        $this->subscriptions->put($subscription);

        return ['handled' => true, 'type' => 'customer.subscription.updated'];
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array{handled: bool, type: string}
     */
    private function onSubscriptionDeleted(array $object): array
    {
        $subscription = $this->findByStripeSubscription((string) ($object['id'] ?? ''));
        if (! $subscription) {
            return ['handled' => false, 'type' => 'customer.subscription.deleted'];
        }
        $subscription['status'] = 'canceled';
        $this->subscriptions->put($subscription);
        $this->syncCompanyStatus($subscription['companyId'], 'churned');

        return ['handled' => true, 'type' => 'customer.subscription.deleted'];
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array{handled: bool, type: string}
     */
    private function onInvoicePaid(array $object): array
    {
        $companyId = (string) ($object['metadata']['companyId'] ?? '');
        $subscription = null;
        if (! empty($object['subscription'])) {
            $subscription = $this->findByStripeSubscription((string) $object['subscription']);
        }
        if (! $subscription && $companyId !== '') {
            $subscription = $this->subscriptionFor($companyId);
        }
        if (! $subscription) {
            return ['handled' => false, 'type' => 'invoice.paid'];
        }

        $companyId = (string) $subscription['companyId'];
        $amount = ((int) ($object['amount_paid'] ?? 0)) / 100;
        $stripeInvoiceId = (string) ($object['id'] ?? '');
        $existing = $this->invoices->findByStripeInvoiceId($stripeInvoiceId);
        if (! $existing) {
            $this->invoices->put([
                'id' => Uid::make('pinv'),
                'companyId' => $companyId,
                'subscriptionId' => $subscription['id'],
                'number' => 'ST-'.strtoupper(substr($stripeInvoiceId ?: Uid::make('inv'), -8)),
                'amount' => $amount,
                'status' => 'paid',
                'issuedAt' => now()->toDateString(),
                'dueDate' => now()->toDateString(),
                'paidAt' => now()->toDateString(),
                'paymentProvider' => 'stripe',
                'paymentRef' => $stripeInvoiceId,
                'stripeInvoiceId' => $stripeInvoiceId,
            ]);
        } else {
            $existing['status'] = 'paid';
            $existing['paidAt'] = now()->toDateString();
            $existing['paymentProvider'] = 'stripe';
            $existing['paymentRef'] = $stripeInvoiceId;
            $existing['stripeInvoiceId'] = $stripeInvoiceId;
            $this->invoices->put($existing);
        }

        if (($subscription['status'] ?? '') === 'past_due') {
            $subscription['status'] = 'active';
            $this->subscriptions->put($subscription);
            $this->syncCompanyStatus($companyId, 'active');
        }

        return ['handled' => true, 'type' => 'invoice.paid'];
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array{handled: bool, type: string}
     */
    private function onInvoicePaymentFailed(array $object): array
    {
        $subscription = null;
        if (! empty($object['subscription'])) {
            $subscription = $this->findByStripeSubscription((string) $object['subscription']);
        }
        if (! $subscription && ! empty($object['metadata']['companyId'])) {
            $subscription = $this->subscriptionFor((string) $object['metadata']['companyId']);
        }
        if (! $subscription) {
            return ['handled' => false, 'type' => 'invoice.payment_failed'];
        }
        $subscription['status'] = 'past_due';
        $this->subscriptions->put($subscription);

        return ['handled' => true, 'type' => 'invoice.payment_failed'];
    }

    /**
     * @param  array<string, mixed>  $subscription
     * @return array{url: string, sessionId: string, provider: string}
     */
    private function checkoutForInvoice(Company $company, array $subscription, string $customerId, string $invoiceId): array
    {
        $invoice = $this->invoices->findOrFail($invoiceId, 'Facture introuvable.');
        $this->tenant->assertCompanyAccess($invoice['companyId'] ?? null);
        if (($invoice['companyId'] ?? null) !== $company->id) {
            throw new DomainException('Facture introuvable.');
        }
        if (! in_array($invoice['status'] ?? '', ['open', 'overdue'], true)) {
            throw new DomainException('Cette facture n’est pas payable.');
        }
        $amountCents = (int) round(((float) ($invoice['amount'] ?? 0)) * 100);
        if ($amountCents < 1) {
            throw new DomainException('Montant de facture invalide.');
        }

        $session = $this->stripe->createCheckoutSession([
            'mode' => 'payment',
            'customer' => $customerId,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => config('services.billing.stripe.currency', 'mad'),
                    'unit_amount' => $amountCents,
                    'product_data' => [
                        'name' => 'Facture '.($invoice['number'] ?? $invoiceId),
                    ],
                ],
            ]],
            'success_url' => $this->checkoutUrl(config('services.billing.stripe.success_url')),
            'cancel_url' => $this->returnUrl(config('services.billing.stripe.cancel_url')),
            'client_reference_id' => $company->id,
            'metadata' => [
                'companyId' => $company->id,
                'subscriptionId' => $subscription['id'],
                'platformInvoiceId' => $invoiceId,
            ],
        ]);

        $subscription['stripeCustomerId'] = $customerId;
        $this->subscriptions->put($subscription);

        return [
            'url' => $session['url'],
            'sessionId' => $session['id'],
            'provider' => 'stripe',
        ];
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    private function ensureCustomer(Company $company, array &$subscription): string
    {
        if (! empty($subscription['stripeCustomerId'])) {
            return (string) $subscription['stripeCustomerId'];
        }
        $customer = $this->stripe->createCustomer([
            'name' => $company->name,
            'email' => $company->email ?: null,
            'metadata' => [
                'companyId' => $company->id,
            ],
        ]);
        $subscription['stripeCustomerId'] = $customer['id'];
        $this->subscriptions->put($subscription);

        return $customer['id'];
    }

    /** @param  array<string, mixed>  $plan */
    private function priceIdFor(array $plan, string $interval): string
    {
        $key = $interval === 'yearly' ? 'stripePriceYearly' : 'stripePriceMonthly';
        $priceId = trim((string) ($plan[$key] ?? ''));
        if ($priceId === '') {
            throw new DomainException("Price Stripe manquant sur le plan ({$key}).");
        }

        return $priceId;
    }

    private function markPlatformInvoicePaid(string $invoiceId, string $paymentRef): void
    {
        $invoice = $this->invoices->find($invoiceId);
        if (! $invoice || ($invoice['status'] ?? '') === 'paid') {
            return;
        }
        $invoice['status'] = 'paid';
        $invoice['paidAt'] = now()->toDateString();
        $invoice['paymentProvider'] = 'stripe';
        $invoice['paymentRef'] = $paymentRef;
        $this->invoices->put($invoice);
    }

    private function syncCompanyStatus(string $companyId, string $status): void
    {
        $company = Company::query()->find($companyId);
        if (! $company) {
            return;
        }
        $company->status = $status;
        $company->save();
    }

    private function companyStatusFor(string $subscriptionStatus): string
    {
        return match ($subscriptionStatus) {
            'paused' => 'suspended',
            'canceled' => 'churned',
            'trialing' => 'trial',
            default => 'active',
        };
    }

    private function mapStripeStatus(string $status): ?string
    {
        return match ($status) {
            'active' => 'active',
            'trialing' => 'trialing',
            'past_due', 'unpaid' => 'past_due',
            'canceled' => 'canceled',
            'paused' => 'paused',
            default => null,
        };
    }

    /** @return array<string, mixed>|null */
    private function subscriptionFor(string $companyId): ?array
    {
        return $this->subscriptions->forCompany($companyId);
    }

    /** @return array<string, mixed>|null */
    private function findByStripeSubscription(string $stripeSubscriptionId): ?array
    {
        return $this->subscriptions->findByStripeSubscription($stripeSubscriptionId);
    }

    private function resolveCompanyId(?string $companyId): string
    {
        $id = $companyId ?: $this->tenant->companyId();
        if (! $id) {
            throw new DomainException('Entreprise introuvable.');
        }

        return $id;
    }

    private function assertStripeEnabled(): void
    {
        if (config('services.billing.provider') !== 'stripe') {
            throw new DomainException('Le fournisseur de paiement Stripe n’est pas actif.');
        }
        if (! config('services.billing.stripe.secret')) {
            throw new DomainException('STRIPE_SECRET manquant.');
        }
    }

    private function returnUrl(?string $configured): string
    {
        $fallback = rtrim((string) config('app.frontend_url', config('app.url')), '/').'/account?tab=billing';

        return trim((string) ($configured ?: $fallback));
    }

    private function checkoutUrl(?string $configured): string
    {
        $value = $this->returnUrl($configured);
        if (str_contains($value, '{CHECKOUT_SESSION_ID}')) {
            return $value;
        }

        return $value.(str_contains($value, '?') ? '&' : '?').'session_id={CHECKOUT_SESSION_ID}';
    }
}
