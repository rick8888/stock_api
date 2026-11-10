<?php

namespace App\Repositories\Eloquent;

use App\Models\Expense;
use Illuminate\Support\Collection;

class ExpenseRepository extends DocumentRepository
{
    protected string $modelClass = Expense::class;

    protected string $idPrefix = 'exp';

    public function forCompany(?string $companyId): Collection
    {
        if (! $companyId) {
            return collect();
        }

        return Expense::query()
            ->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId)->orWhereNull('company_id');
            })
            ->orderByDesc('spent_at')
            ->get()
            ->map(fn (Expense $model) => $model->document())
            ->values();
    }
}
