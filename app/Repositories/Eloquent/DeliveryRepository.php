<?php

namespace App\Repositories\Eloquent;

use App\Models\Delivery;

class DeliveryRepository extends DocumentRepository
{
    protected string $modelClass = Delivery::class;

    protected string $idPrefix = 'dlv';
}
