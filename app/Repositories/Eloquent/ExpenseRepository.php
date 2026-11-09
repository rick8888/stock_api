<?php

namespace App\Repositories\Eloquent;

use App\Models\Expense;

class ExpenseRepository extends DocumentRepository
{
    protected string $modelClass = Expense::class;

    protected string $idPrefix = 'exp';
}
