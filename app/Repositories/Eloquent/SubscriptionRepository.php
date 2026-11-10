<?php

namespace App\Repositories\Eloquent;

use App\Models\Subscription;
use Illuminate\Support\Collection;

class SubscriptionRepository extends DocumentRepository
{
    protected string $modelClass = Subscription::class;

    protected string $idPrefix = 'sub';

    public function forCompany(?string $companyId): ?array
    {
        if (! $companyId) {
            return null;
        }

        return Subscription::query()
            ->where('company_id', $companyId)
            ->orderByDesc('started_at')
            ->first()
            ?->document();
    }

    public function forCompanies(): Collection
    {
        return Subscription::query()
            ->orderByDesc('started_at')
            ->get()
            ->map(fn (Subscription $model) => $model->document())
            ->values();
    }

    public function findByStripeSubscription(string $stripeSubscriptionId): ?array
    {
        if ($stripeSubscriptionId === '') {
            return null;
        }

        return Subscription::query()
            ->where('stripe_subscription_id', $stripeSubscriptionId)
            ->first()
            ?->document();
    }

    public function expiredTrials(?string $asOf = null): Collection
    {
        $asOf ??= now()->toDateString();

        return Subscription::query()
            ->where('status', 'trialing')
            ->whereNotNull('trial_ends_at')
            ->whereDate('trial_ends_at', '<', $asOf)
            ->get()
            ->map(fn (Subscription $model) => $model->document())
            ->values();
    }
}
