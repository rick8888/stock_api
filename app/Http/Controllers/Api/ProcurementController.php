<?php

namespace App\Http\Controllers\Api;

use App\Services\ProcurementService;
use Illuminate\Http\Request;

class ProcurementController extends ApiController
{
    public function __construct(private readonly ProcurementService $procurement) {}

    public function index()
    {
        return $this->items($this->procurement->list());
    }

    public function store(Request $request)
    {
        return $this->one($this->procurement->create($request->all()));
    }

    public function update(Request $request, string $id)
    {
        return $this->one($this->procurement->updateStatus(
            $id,
            $request->string('status')->toString(),
            $request->except('status'),
        ));
    }
}
