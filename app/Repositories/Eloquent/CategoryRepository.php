<?php

namespace App\Repositories\Eloquent;

use App\Models\Category;

class CategoryRepository extends DocumentRepository
{
    protected string $modelClass = Category::class;

    protected string $idPrefix = 'cat';
}
