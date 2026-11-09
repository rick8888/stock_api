<?php

namespace App\Http\Controllers\Api;

use App\Services\StockService;
use Illuminate\Http\Request;

class StockController extends ApiController
{
    public function __construct(private readonly StockService $stock) {}

    public function index()
    {
        return $this->items($this->stock->list());
    }

    public function movements()
    {
        return $this->items($this->stock->movements());
    }

    public function inventories()
    {
        return $this->items($this->stock->inventories());
    }

    public function receive(Request $request)
    {
        $this->stock->receive(
            $request->string('productId')->toString(),
            $request->string('warehouseId')->toString(),
            $request->integer('quantity'),
            $request->string('userId')->toString(),
            $request->string('note')->toString(),
            $request->input('reference'),
        );

        return $this->empty();
    }

    public function issue(Request $request)
    {
        $this->stock->issue(
            $request->string('productId')->toString(),
            $request->string('warehouseId')->toString(),
            $request->integer('quantity'),
            $request->string('userId')->toString(),
            $request->string('note')->toString(),
            $request->input('reference'),
        );

        return $this->empty();
    }

    public function transfer(Request $request)
    {
        $this->stock->transfer(
            $request->string('productId')->toString(),
            $request->string('fromWarehouseId')->toString(),
            $request->string('toWarehouseId')->toString(),
            $request->integer('quantity'),
            $request->string('userId')->toString(),
            $request->input('note', 'Transfert'),
        );

        return $this->empty();
    }

    public function adjust(Request $request)
    {
        $this->stock->adjust(
            $request->string('productId')->toString(),
            $request->string('warehouseId')->toString(),
            $request->integer('quantity'),
            $request->string('userId')->toString(),
            $request->string('note')->toString(),
            $request->input('reference'),
        );

        return $this->empty();
    }

    public function assignment(Request $request)
    {
        $this->stock->toggleAssignment(
            $request->string('productId')->toString(),
            $request->string('warehouseId')->toString(),
            $request->boolean('enabled'),
        );

        return $this->empty();
    }

    public function startInventory(Request $request)
    {
        return $this->one($this->stock->startInventory(
            $request->string('warehouseId')->toString(),
            $request->string('userId')->toString(),
        ));
    }

    public function saveInventory(Request $request, string $id)
    {
        return $this->one($this->stock->saveInventory($id, $request->input('lines', [])));
    }

    public function submitInventory(Request $request, string $id)
    {
        return $this->one($this->stock->submitInventory($id, $request->string('userId')->toString()));
    }

    public function completeInventory(Request $request, string $id)
    {
        return $this->one($this->stock->completeInventory($id, $request->string('userId')->toString()));
    }

    public function closeDay(Request $request)
    {
        $this->stock->closeDay(
            $request->string('warehouseId')->toString(),
            $request->string('userId')->toString(),
        );

        return $this->empty();
    }

    public function returnStock(Request $request)
    {
        $this->stock->returnStock(
            $request->string('productId')->toString(),
            $request->string('warehouseId')->toString(),
            $request->integer('quantity'),
            $request->string('userId')->toString(),
            $request->string('note')->toString(),
            $request->input('reference'),
        );

        return $this->empty();
    }
}
