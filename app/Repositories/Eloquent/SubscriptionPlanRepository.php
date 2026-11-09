<?php

namespace App\Repositories\Eloquent;

use App\Models\SubscriptionPlan;

class SubscriptionPlanRepository extends DocumentRepository
{
    protected string $modelClass = SubscriptionPlan::class;

    protected string $idPrefix = 'plan';
}
