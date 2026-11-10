<?php

namespace App\Http\Controllers\Api;

use App\Services\CustomerService;
use Illuminate\Http\Request;

class CustomerController extends ApiController
{
    public function __construct(private readonly CustomerService $customers) {}

    public function index()
    {
        return $this->items($this->customers->list());
    }

    public function show(string $id)
    {
        return $this->one($this->customers->get($id), 'Client introuvable');
    }

    public function store(Request $request)
    {
        return $this->one($this->customers->create($request->all()));
    }

    public function update(Request $request, string $id)
    {
        return $this->one($this->customers->update($id, $request->all()));
    }
}
