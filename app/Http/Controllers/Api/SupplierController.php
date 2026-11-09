<?php

namespace App\Http\Controllers\Api;

use App\Services\SupplierService;
use Illuminate\Http\Request;

class SupplierController extends ApiController
{
    public function __construct(private readonly SupplierService $suppliers) {}

    public function index()
    {
        return $this->items($this->suppliers->list());
    }

    public function show(string $id)
    {
        return $this->one($this->suppliers->get($id), 'Fournisseur introuvable');
    }

    public function store(Request $request)
    {
        return $this->one($this->suppliers->create($request->all()));
    }

    public function update(Request $request, string $id)
    {
        return $this->one($this->suppliers->update($id, $request->all()));
    }
}
