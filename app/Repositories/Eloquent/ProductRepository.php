<?php

namespace App\Repositories\Eloquent;

use App\Models\Product;

class ProductRepository extends DocumentRepository
{
    protected string $modelClass = Product::class;

    protected string $idPrefix = 'prd';
}
