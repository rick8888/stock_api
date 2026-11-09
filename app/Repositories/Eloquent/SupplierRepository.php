<?php

namespace App\Repositories\Eloquent;

use App\Models\Supplier;

class SupplierRepository extends DocumentRepository
{
    protected string $modelClass = Supplier::class;

    protected string $idPrefix = 'sup';
}
