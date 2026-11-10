<?php

namespace App\Repositories\Eloquent;

use App\Models\Invoice;
use Illuminate\Support\Collection;

class InvoiceRepository extends DocumentRepository
{
    protected string $modelClass = Invoice::class;

    protected string $idPrefix = 'invc';

    public function forCompany(?string $companyId): Collection
    {
        if (! $companyId) {
            return collect();
        }

        return Invoice::query()
            ->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId)->orWhereNull('company_id');
            })
            ->orderBy('due_date')
            ->get()
            ->map(fn (Invoice $model) => $model->document())
            ->values();
    }
}
