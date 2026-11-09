<?php

namespace App\Http\Controllers\Api;

use App\Services\WarehouseService;
use Illuminate\Http\Request;

class WarehouseController extends ApiController
{
    public function __construct(private readonly WarehouseService $warehouses) {}

    public function index()
    {
        return $this->items($this->warehouses->list());
    }

    public function show(string $id)
    {
        return $this->one($this->warehouses->get($id), 'Entrepôt introuvable');
    }

    public function store(Request $request)
    {
        return $this->one($this->warehouses->create($request->all()));
    }

    public function update(Request $request, string $id)
    {
        return $this->one($this->warehouses->update($id, $request->all()));
    }
}
