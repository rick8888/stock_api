<?php

namespace App\Http\Controllers\Api;

use App\Services\PlatformService;
use Illuminate\Http\Request;

class PlatformController extends ApiController
{
    public function __construct(private readonly PlatformService $platform) {}

    public function companies()
    {
        return $this->items($this->platform->companies());
    }

    public function storeCompany(Request $request)
    {
        return $this->one($this->platform->createCompany($request->all()));
    }

    public function updateCompany(Request $request, string $id)
    {
        return $this->one($this->platform->updateCompany($id, $request->all()));
    }

    public function plans()
    {
        return $this->items($this->platform->plans());
    }

    public function storePlan(Request $request)
    {
        return $this->one($this->platform->createPlan($request->all()));
    }

    public function updatePlan(Request $request, string $id)
    {
        return $this->one($this->platform->updatePlan($id, $request->all()));
    }

    public function subscriptions()
    {
        return $this->items($this->platform->subscriptions());
    }

    public function invoices()
    {
        return $this->items($this->platform->invoices());
    }

    public function changeSubscription(Request $request, string $companyId)
    {
        return $this->one($this->platform->changeSubscription($companyId, $request->all()));
    }

    public function payInvoice(string $id)
    {
        $this->platform->payInvoice($id);

        return $this->empty();
    }
}
