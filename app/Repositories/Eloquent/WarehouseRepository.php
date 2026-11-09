<?php

namespace App\Repositories\Eloquent;

use App\Models\Warehouse;

class WarehouseRepository extends DocumentRepository
{
    protected string $modelClass = Warehouse::class;

    protected string $idPrefix = 'wh';
}
