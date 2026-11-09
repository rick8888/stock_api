<?php

namespace App\Repositories\Eloquent;

use App\Models\Budget;

class BudgetRepository extends DocumentRepository
{
    protected string $modelClass = Budget::class;

    protected string $idPrefix = 'bdg';
}
