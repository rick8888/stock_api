<?php

namespace App\Repositories\Eloquent;

use App\Models\PurchaseOrder;

class PurchaseOrderRepository extends DocumentRepository
{
    protected string $modelClass = PurchaseOrder::class;

    protected string $idPrefix = 'po';
}
