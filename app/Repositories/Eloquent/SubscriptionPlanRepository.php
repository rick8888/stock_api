<?php

namespace App\Repositories\Eloquent;

use App\Models\SubscriptionPlan;
use Illuminate\Support\Collection;

class SubscriptionPlanRepository extends DocumentRepository
{
    protected string $modelClass = SubscriptionPlan::class;

    protected string $idPrefix = 'plan';

    public function all(): Collection
    {
        return SubscriptionPlan::query()
            ->orderBy('name')
            ->get()
            ->map(fn (SubscriptionPlan $model) => $model->document())
            ->values();
    }

    public function active(): Collection
    {
        return SubscriptionPlan::query()
            ->where('active', true)
            ->orderBy('monthly_price')
            ->get()
            ->map(fn (SubscriptionPlan $model) => $model->document())
            ->values();
    }
}
