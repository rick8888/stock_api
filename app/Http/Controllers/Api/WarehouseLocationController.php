<?php

namespace App\Http\Controllers\Api;

use App\Services\WarehouseLocationService;
use Illuminate\Http\Request;

class WarehouseLocationController extends ApiController
{
    public function __construct(private readonly WarehouseLocationService $locations) {}

    public function index(Request $request)
    {
        return $this->items($this->locations->list($request->input('warehouseId')));
    }

    public function show(string $id)
    {
        return $this->one($this->locations->get($id), 'Emplacement introuvable');
    }

    public function store(Request $request)
    {
        return $this->one($this->locations->create($request->all()));
    }

    public function update(Request $request, string $id)
    {
        return $this->one($this->locations->update($id, $request->all()));
    }
}
