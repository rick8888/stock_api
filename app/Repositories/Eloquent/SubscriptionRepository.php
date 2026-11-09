<?php

namespace App\Repositories\Eloquent;

use App\Models\Subscription;

class SubscriptionRepository extends DocumentRepository
{
    protected string $modelClass = Subscription::class;

    protected string $idPrefix = 'sub';
}
