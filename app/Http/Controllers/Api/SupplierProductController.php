<?php

namespace App\Http\Controllers\Api;

use App\Services\SupplierProductService;

class SupplierProductController extends ApiController
{
    public function __construct(private readonly SupplierProductService $catalog) {}

    public function index()
    {
        return $this->items($this->catalog->list());
    }
}
