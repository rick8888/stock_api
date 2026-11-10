<?php

namespace App\Repositories\Eloquent;

use App\Models\Customer;
use Illuminate\Support\Collection;

class CustomerRepository extends DocumentRepository
{
    protected string $modelClass = Customer::class;

    protected string $idPrefix = 'cus';

    public function forCompany(?string $companyId): Collection
    {
        if (! $companyId) {
            return collect();
        }

        return Customer::query()
            ->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId)->orWhereNull('company_id');
            })
            ->orderBy('name')
            ->get()
            ->map(fn (Customer $model) => $model->document())
            ->values();
    }
}
