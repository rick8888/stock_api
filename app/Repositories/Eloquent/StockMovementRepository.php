<?php

namespace App\Repositories\Eloquent;

use App\Models\StockMovement;

class StockMovementRepository extends DocumentRepository
{
    protected string $modelClass = StockMovement::class;

    protected string $idPrefix = 'mov';
}
