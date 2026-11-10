<?php

namespace App\Services;

use App\Repositories\Eloquent\BudgetRepository;
use App\Repositories\Eloquent\ExpenseRepository;
use App\Repositories\Eloquent\InvoiceRepository;
use App\Repositories\Eloquent\PaymentRepository;
use App\Support\Tenant;
use App\Support\Uid;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FinanceService
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly PaymentRepository $payments,
        private readonly BudgetRepository $budgets,
        private readonly ExpenseRepository $expenses,
        private readonly Tenant $tenant,
    ) {}

    public function invoices(): Collection
    {
        if ($this->tenant->bypassTenantFilter()) {
            return $this->invoices->all();
        }

        return $this->invoices->forCompany($this->tenant->companyId());
    }

    public function payments(): Collection
    {
        if ($this->tenant->bypassTenantFilter()) {
            return $this->payments->all();
        }

        return $this->payments->forCompany($this->tenant->companyId());
    }

    public function budgets(): Collection
    {
        if ($this->tenant->bypassTenantFilter()) {
            return $this->budgets->all();
        }

        return $this->budgets->forCompany($this->tenant->companyId());
    }

    public function expenses(): Collection
    {
        if ($this->tenant->bypassTenantFilter()) {
            return $this->expenses->all();
        }

        return $this->expenses->forCompany($this->tenant->companyId());
    }

    public function payInvoice(string $id): void
    {
        DB::transaction(function () use ($id) {
            $invoice = $this->invoices->findOrFail($id, 'Facture introuvable');
            $invoice['status'] = 'paid';
            $this->invoices->put($invoice);
            $this->payments->put([
                'id' => Uid::make('pay'),
                'invoiceId' => $id,
                'amount' => $invoice['amount'] ?? 0,
                'date' => now()->toIso8601String(),
            ]);
        });
    }
}
