<?php

namespace App\Services;

use App\Repositories\Eloquent\BudgetRepository;
use App\Repositories\Eloquent\ExpenseRepository;
use App\Repositories\Eloquent\InvoiceRepository;
use App\Repositories\Eloquent\PaymentRepository;
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
    ) {}

    public function invoices(): Collection
    {
        return $this->invoices->all();
    }

    public function payments(): Collection
    {
        return $this->payments->all();
    }

    public function budgets(): Collection
    {
        return $this->budgets->all();
    }

    public function expenses(): Collection
    {
        return $this->expenses->all();
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
