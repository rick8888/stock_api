<?php

namespace App\Http\Controllers\Api;

use App\Services\PurchaseService;
use Illuminate\Http\Request;

class PurchaseController extends ApiController
{
    public function __construct(private readonly PurchaseService $purchases) {}

    public function index()
    {
        return $this->items($this->purchases->list());
    }

    public function store(Request $request)
    {
        return $this->one($this->purchases->create($request->all()));
    }

    public function update(Request $request, string $id)
    {
        return $this->one($this->purchases->updateStatus($id, $request->string('status')->toString()));
    }
}
