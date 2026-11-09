<?php

namespace App\Repositories\Eloquent;

use App\Models\Receipt;

class ReceiptRepository extends DocumentRepository
{
    protected string $modelClass = Receipt::class;

    protected string $idPrefix = 'rcp';
}
