<?php

namespace App\Http\Controllers\Api;

use App\Services\StockLotService;
use Illuminate\Http\Request;

class StockLotController extends ApiController
{
    public function __construct(private readonly StockLotService $lots) {}

    public function index(Request $request)
    {
        return $this->items($this->lots->list(
            $request->input('warehouseId'),
            $request->input('productId'),
        ));
    }
}
