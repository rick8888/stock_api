<?php

namespace App\Repositories\Eloquent;

use App\Models\PlatformInvoice;
use Illuminate\Support\Collection;

class PlatformInvoiceRepository extends DocumentRepository
{
    protected string $modelClass = PlatformInvoice::class;

    protected string $idPrefix = 'pinv';

    public function forCompany(?string $companyId): Collection
    {
        if (! $companyId) {
            return collect();
        }

        return PlatformInvoice::query()
            ->where('company_id', $companyId)
            ->orderByDesc('issued_at')
            ->get()
            ->map(fn (PlatformInvoice $model) => $model->document())
            ->values();
    }

    public function findByStripeInvoiceId(string $stripeInvoiceId): ?array
    {
        if ($stripeInvoiceId === '') {
            return null;
        }

        return PlatformInvoice::query()
            ->where(function ($query) use ($stripeInvoiceId) {
                $query->where('stripe_invoice_id', $stripeInvoiceId)
                    ->orWhere('payment_ref', $stripeInvoiceId);
            })
            ->first()
            ?->document();
    }

    public function hasOpenForCompany(string $companyId): bool
    {
        return PlatformInvoice::query()
            ->where('company_id', $companyId)
            ->whereIn('status', ['open', 'overdue'])
            ->exists();
    }
}
