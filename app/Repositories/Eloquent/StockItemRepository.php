<?php

namespace App\Repositories\Eloquent;

use App\Models\StockItem;

class StockItemRepository extends DocumentRepository
{
    protected string $modelClass = StockItem::class;

    protected string $idPrefix = 'stk';
}
