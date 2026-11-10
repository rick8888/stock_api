<?php

namespace App\Repositories\Eloquent;

use App\Models\Supplier;
use Illuminate\Support\Collection;

class SupplierRepository extends DocumentRepository
{
    protected string $modelClass = Supplier::class;

    protected string $idPrefix = 'sup';

    public function forCompany(?string $companyId): Collection
    {
        if (! $companyId) {
            return collect();
        }

        return Supplier::query()
            ->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId)->orWhereNull('company_id');
            })
            ->orderBy('name')
            ->get()
            ->map(fn (Supplier $model) => $model->document())
            ->values();
    }
}
