<?php

namespace App\Http\Controllers\Api;

use App\Services\PurchaseFromOfferService;
use App\Services\PurchaseService;
use Illuminate\Http\Request;

class PurchaseController extends ApiController
{
    public function __construct(
        private readonly PurchaseService $purchases,
        private readonly PurchaseFromOfferService $fromOffer,
    ) {}

    public function index()
    {
        return $this->items($this->purchases->list());
    }

    public function store(Request $request)
    {
        return $this->one($this->purchases->create($request->all()));
    }

    public function fromOffer(Request $request)
    {
        $ids = $request->input('requestIds', []);
        if (is_string($ids)) {
            $ids = array_filter(explode(',', $ids));
        }
        if (! is_array($ids)) {
            $ids = [];
        }

        return $this->one($this->fromOffer->create(
            array_values($ids),
            (string) $request->input('supplierId', ''),
        ));
    }

    public function update(Request $request, string $id)
    {
        return $this->one($this->purchases->updateStatus($id, $request->string('status')->toString()));
    }
}
