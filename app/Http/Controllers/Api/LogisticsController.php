<?php

namespace App\Http\Controllers\Api;

use App\Services\LogisticsService;
use Illuminate\Http\Request;

class LogisticsController extends ApiController
{
    public function __construct(private readonly LogisticsService $logistics) {}

    public function shipments()
    {
        return $this->items($this->logistics->shipments());
    }

    public function storeShipment(Request $request)
    {
        return $this->one($this->logistics->createShipment($request->all()));
    }

    public function updateShipment(Request $request, string $id)
    {
        return $this->one($this->logistics->updateShipment($id, $request->all(), $this->actorId()));
    }

    public function routes()
    {
        return $this->items($this->logistics->routes());
    }

    public function storeRoute(Request $request)
    {
        return $this->one($this->logistics->createRoute($request->all()));
    }

    public function updateRoute(Request $request, string $id)
    {
        return $this->one($this->logistics->updateRoute($id, $request->all()));
    }

    public function vehicles()
    {
        return $this->items($this->logistics->vehicles());
    }

    public function storeVehicle(Request $request)
    {
        return $this->one($this->logistics->createVehicle($request->all()));
    }

    public function updateVehicle(Request $request, string $id)
    {
        return $this->one($this->logistics->updateVehicle($id, $request->all()));
    }

    public function drivers()
    {
        return $this->items($this->logistics->drivers());
    }

    public function storeDriver(Request $request)
    {
        return $this->one($this->logistics->createDriver($request->all()));
    }

    public function updateDriver(Request $request, string $id)
    {
        return $this->one($this->logistics->updateDriver($id, $request->all()));
    }

    public function shippingNotes()
    {
        return $this->items($this->logistics->shippingNotes());
    }

    public function storeShippingNote(Request $request)
    {
        return $this->one($this->logistics->createShippingNote($request->all()));
    }

    public function updateShippingNote(Request $request, string $id)
    {
        return $this->one($this->logistics->updateShippingNote($id, $request->all()));
    }

    public function assignCarrier(Request $request, string $id)
    {
        return $this->one($this->logistics->assignCarrier($id, $request->all()));
    }

    public function markLabelPrinted(string $id)
    {
        return $this->one($this->logistics->markShippingNoteLabelPrinted($id));
    }

    public function carriers()
    {
        return $this->items(collect($this->logistics->carriers()));
    }

    public function loadShippingNote(Request $request, string $id)
    {
        return $this->one($this->logistics->loadShippingNote($id, $this->actorId()));
    }
}
