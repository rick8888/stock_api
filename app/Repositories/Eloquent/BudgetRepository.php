<?php

namespace App\Repositories\Eloquent;

use App\Models\Budget;
use Illuminate\Support\Collection;

class BudgetRepository extends DocumentRepository
{
    protected string $modelClass = Budget::class;

    protected string $idPrefix = 'bdg';

    public function forCompany(?string $companyId): Collection
    {
        if (! $companyId) {
            return collect();
        }

        return Budget::query()
            ->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId)->orWhereNull('company_id');
            })
            ->get()
            ->map(fn (Budget $model) => $model->document())
            ->values();
    }
}
