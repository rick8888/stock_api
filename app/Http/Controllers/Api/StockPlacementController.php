<?php

namespace App\Http\Controllers\Api;

use App\Services\StockPlacementService;
use Illuminate\Http\Request;

class StockPlacementController extends ApiController
{
    public function __construct(private readonly StockPlacementService $placements) {}

    public function index(Request $request)
    {
        return $this->items($this->placements->list(
            $request->input('warehouseId'),
            $request->input('productId'),
        ));
    }

    public function pickPath(Request $request)
    {
        $warehouseId = (string) $request->input('warehouseId', '');
        $lines = $request->input('lines', []);
        if (! is_array($lines)) {
            $lines = [];
        }

        return response()->json([
            'items' => $this->placements->pickPath($warehouseId, $lines),
        ]);
    }

    public function suggestPutaway(Request $request)
    {
        $warehouseId = (string) $request->input('warehouseId', '');
        $productId = (string) $request->input('productId', '');

        return $this->one(
            $this->placements->suggestPutaway($warehouseId, $productId),
            'Aucun emplacement suggéré',
        );
    }
}
