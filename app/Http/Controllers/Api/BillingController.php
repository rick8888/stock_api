<?php

namespace App\Http\Controllers\Api;

use App\Services\StripeBillingService;
use Illuminate\Http\Request;

class BillingController extends ApiController
{
    public function __construct(private readonly StripeBillingService $billing) {}

    public function checkout(Request $request)
    {
        return response()->json($this->billing->createCheckoutSession(
            $request->input('companyId'),
            $request->input('planId'),
            $request->input('interval'),
            $request->input('invoiceId'),
        ));
    }

    public function portal(Request $request)
    {
        return response()->json($this->billing->createPortalSession(
            $request->input('companyId'),
        ));
    }

    public function webhook(Request $request)
    {
        $result = $this->billing->handleWebhook(
            $request->getContent(),
            (string) $request->header('Stripe-Signature', ''),
        );

        return response()->json($result);
    }
}
