<?php

namespace App\Repositories\Eloquent;

use App\Models\Payment;
use Illuminate\Support\Collection;

class PaymentRepository extends DocumentRepository
{
    protected string $modelClass = Payment::class;

    protected string $idPrefix = 'pay';

    public function forCompany(?string $companyId): Collection
    {
        if (! $companyId) {
            return collect();
        }

        return Payment::query()
            ->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId)->orWhereNull('company_id');
            })
            ->orderByDesc('paid_at')
            ->get()
            ->map(fn (Payment $model) => $model->document())
            ->values();
    }
}
