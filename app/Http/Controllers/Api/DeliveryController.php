<?php

namespace App\Http\Controllers\Api;

use App\Services\DeliveryService;
use Illuminate\Http\Request;

class DeliveryController extends ApiController
{
    public function __construct(private readonly DeliveryService $deliveries) {}

    public function index()
    {
        return $this->items($this->deliveries->list());
    }

    public function store(Request $request)
    {
        return $this->one($this->deliveries->create($request->all()));
    }

    public function update(Request $request, string $id)
    {
        return $this->one($this->deliveries->update($id, $request->all()));
    }

    public function updateStatus(Request $request, string $id)
    {
        return $this->one($this->deliveries->updateStatus(
            $id,
            $request->string('status')->toString(),
            $request->input('note'),
            $request->input('signedBy'),
        ));
    }
}
