<?php

namespace App\Http\Controllers\Api;

use App\Services\ProductService;
use Illuminate\Http\Request;

class ProductController extends ApiController
{
    public function __construct(private readonly ProductService $products) {}

    public function index()
    {
        return $this->items($this->products->list());
    }

    public function categories()
    {
        return $this->items($this->products->categories());
    }

    public function show(string $id)
    {
        return $this->one($this->products->get($id), 'Produit introuvable');
    }

    public function store(Request $request)
    {
        return $this->one($this->products->create($request->all()));
    }

    public function update(Request $request, string $id)
    {
        return $this->one($this->products->update($id, $request->all()));
    }

    public function destroy(string $id)
    {
        $this->products->archive($id);

        return $this->empty();
    }
}
