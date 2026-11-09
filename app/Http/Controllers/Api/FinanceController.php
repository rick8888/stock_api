<?php

namespace App\Http\Controllers\Api;

use App\Services\FinanceService;
use Illuminate\Http\Request;

class FinanceController extends ApiController
{
    public function __construct(private readonly FinanceService $finance) {}

    public function invoices()
    {
        return $this->items($this->finance->invoices());
    }

    public function payments()
    {
        return $this->items($this->finance->payments());
    }

    public function budgets()
    {
        return $this->items($this->finance->budgets());
    }

    public function expenses()
    {
        return $this->items($this->finance->expenses());
    }

    public function payInvoice(string $id)
    {
        $this->finance->payInvoice($id);

        return $this->empty();
    }
}
